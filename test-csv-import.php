<?php
/**
 * Standalone test script for CSV import validation
 */

// Copy the sanitization functions from the plugin
function subsales_sanitize_team_name( $name ) {
    // First do basic text sanitization
    $name = trim( $name );
    
    // Allow only: letters (any language), numbers, spaces, dash, underscore, apostrophe, period, comma, ampersand, parentheses
    // Remove all other special characters that could cause issues
    $name = preg_replace( '/[^\p{L}\p{N}\s\-_\'.,&()]/u', '', $name );
    
    // Collapse multiple spaces to single space
    $name = preg_replace( '/\s+/', ' ', $name );
    
    // Trim whitespace
    $name = trim( $name );
    
    return $name;
}

function subsales_sanitize_user_name( $name ) {
    // First do basic text sanitization
    $name = trim( $name );
    
    // Allow: letters (any language), numbers, spaces, dash, underscore, apostrophe, period
    $name = preg_replace( '/[^\p{L}\p{N}\s\-_\'.]/u', '', $name );
    
    // Collapse multiple spaces
    $name = preg_replace( '/\s+/', ' ', $name );
    
    // Trim
    $name = trim( $name );
    
    return $name;
}

// Simplified version of the import preview function
function test_import_preview( $file_path ) {
    $handle = fopen( $file_path, 'r' );
    if ( ! $handle ) {
        return array( 'error' => 'Could not read file.' );
    }
    
    $teams = array();
    $users = array();
    $errors = array();
    $section = null;
    $line_num = 0;
    $teams_header_found = false;
    $users_header_found = false;
    
    while ( ( $row = fgetcsv( $handle ) ) !== false ) {
        $line_num++;
        
        // Skip empty rows
        if ( empty( array_filter( $row ) ) ) continue;
        
        // Skip comment lines
        if ( isset( $row[0] ) && str_starts_with( trim( $row[0] ), '#' ) ) {
            // Check for section markers
            if ( stripos( $row[0], 'TEAMS SECTION' ) !== false ) {
                $section = 'teams';
                echo "Line {$line_num}: Found TEAMS SECTION marker\n";
            } elseif ( stripos( $row[0], 'USERS SECTION' ) !== false ) {
                $section = 'users';
                echo "Line {$line_num}: Found USERS SECTION marker\n";
            }
            continue;
        }
        
        // Check for headers
        if ( $section === 'teams' && ! $teams_header_found && isset( $row[0] ) && strtolower( trim( $row[0] ) ) === 'team_name' ) {
            $teams_header_found = true;
            echo "Line {$line_num}: Found teams header\n";
            continue;
        }
        if ( $section === 'users' && ! $users_header_found && isset( $row[0] ) && strtolower( trim( $row[0] ) ) === 'name' ) {
            $users_header_found = true;
            echo "Line {$line_num}: Found users header\n";
            continue;
        }
        
        // Process team rows (header is optional for teams section)
        if ( $section === 'teams' ) {
            if ( count( $row ) < 3 ) {
                $errors[] = "Line {$line_num}: Invalid team format (need: team_name, access_code, status)";
                continue;
            }
            
            $team_name = trim( $row[0] );
            $access_code = trim( $row[1] );
            $status = trim( strtolower( $row[2] ) );
            
            if ( empty( $team_name ) ) {
                $errors[] = "Line {$line_num}: Team name is required";
                continue;
            }
            
            echo "Line {$line_num}: Team raw='{$team_name}' sanitized='" . subsales_sanitize_team_name( $team_name ) . "'\n";
            
            $teams[] = array(
                'name' => $team_name,
                'access_code' => $access_code,
                'status' => $status
            );
        }
        
        // Process user rows
        if ( $section === 'users' && $users_header_found ) {
            if ( count( $row ) < 5 ) {
                $errors[] = "Line {$line_num}: Invalid user format (need: name, phone, email, status, teams)";
                continue;
            }
            
            $name = trim( $row[0] );
            $phone = trim( $row[1] );
            $email = trim( $row[2] );
            $status = trim( strtolower( $row[3] ) );
            $user_teams = trim( $row[4] );
            
            if ( empty( $name ) ) {
                continue;
            }
            
            // Parse teams - collect all columns from index 4 onwards
            // Each non-empty column is a team name (supports quoted team names with commas)
            $team_list = array();
            for ( $i = 4; $i < count( $row ); $i++ ) {
                $team_name = trim( $row[$i] );
                if ( ! empty( $team_name ) ) {
                    $team_list[] = $team_name;
                }
            }
            
            if ( ! empty( $team_list ) ) {
                echo "Line {$line_num}: User '{$name}' teams='" . implode("', '", $team_list) . "'\n";
                
                // Validate teams exist
                foreach ( $team_list as $team_name ) {
                    $sanitized_user_team = subsales_sanitize_team_name( $team_name );
                    $team_exists = false;
                    $matches = array();
                    
                    foreach ( $teams as $team ) {
                        $sanitized_team = subsales_sanitize_team_name( $team['name'] );
                        if ( $sanitized_team === $sanitized_user_team ) {
                            $team_exists = true;
                            break;
                        }
                        $matches[] = $sanitized_team;
                    }
                    
                    if ( ! $team_exists ) {
                        $errors[] = "Line {$line_num}: Team '{$team_name}' (sanitized: '{$sanitized_user_team}') not found for user '{$name}'. Available: '" . implode("', '", $matches) . "'";
                    }
                }
            }
        }
    }
    
    fclose( $handle );
    
    return array(
        'teams' => $teams,
        'users' => $users,
        'errors' => $errors
    );
}

// Run the test
echo "Testing CSV import preview...\n";
echo "================================\n\n";

$result = test_import_preview( '/workspaces/Southington-BKMB-Subsales/subsales-users-teams-2026-01-06.csv' );

echo "\n================================\n";
echo "Results:\n";
echo "Teams found: " . count( $result['teams'] ) . "\n";
echo "Users found: " . count( $result['users'] ) . "\n";
echo "Errors: " . count( $result['errors'] ) . "\n\n";

if ( ! empty( $result['errors'] ) ) {
    echo "ERRORS:\n";
    foreach ( $result['errors'] as $error ) {
        echo "  - {$error}\n";
    }
}
