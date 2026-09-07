<?php
/**
 * Reading and writing the seller sheet as a real spreadsheet.
 *
 * The sheet goes out to a band director who may have nothing set up to open a
 * .csv - it can land in Notepad, or be mangled by whatever claims the extension.
 * An .xlsx opens in Excel, Numbers, Google Sheets and LibreOffice the way a
 * volunteer expects, so that is what we hand out and what we take back.
 *
 * An .xlsx is a zip of XML, and ZipArchive plus SimpleXML are both present, so
 * this reads and writes it directly rather than adding a spreadsheet library
 * for three columns of text.
 *
 * @package Subsales_Management
 * @since 3.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_Sheet {

    /** Can this install produce and read .xlsx at all? */
    public static function xlsx_supported() {
        return class_exists( 'ZipArchive' ) && class_exists( 'SimpleXMLElement' );
    }

    /**
     * Send an .xlsx to the browser.
     *
     * Every cell is written as an inline string, so nothing needs a shared
     * string table and - more to the point - a phone number cannot be taken for
     * a number and handed back as 8.60555E+09 or with its leading zero eaten.
     * The columns are formatted as text for the same reason, so a director
     * retyping one gets what they typed.
     *
     * @param array  $headers Column headings.
     * @param array  $rows    Rows of scalar values.
     * @param string $sheet   Tab name.
     */
    public static function write_xlsx( $headers, $rows, $sheet = 'Sellers' ) {
        $tmp = wp_tempnam( 'subsales-sheet' );
        if ( ! $tmp ) {
            return false;
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
            return false;
        }

        $zip->addFromString( '[Content_Types].xml', self::content_types() );
        $zip->addFromString( '_rels/.rels', self::root_rels() );
        $zip->addFromString( 'xl/workbook.xml', self::workbook( $sheet ) );
        $zip->addFromString( 'xl/_rels/workbook.xml.rels', self::workbook_rels() );
        $zip->addFromString( 'xl/styles.xml', self::styles() );
        $zip->addFromString( 'xl/worksheets/sheet1.xml', self::sheet_xml( $headers, $rows ) );
        $zip->close();

        $data = file_get_contents( $tmp );
        @unlink( $tmp );
        return $data;
    }

    /**
     * Rows out of an uploaded sheet, whichever format it arrived in.
     *
     * @param string $path Local file path.
     * @param string $name Original filename, for picking the reader.
     * @return array|WP_Error Rows of cell arrays.
     */
    public static function read_rows( $path, $name = '' ) {
        $ext = strtolower( pathinfo( $name ? $name : $path, PATHINFO_EXTENSION ) );

        if ( 'xlsx' === $ext ) {
            return self::read_xlsx( $path );
        }
        if ( 'xls' === $ext ) {
            return new WP_Error( 'old_excel', 'That is an older .xls file. Open it in Excel and use File > Save As to save it as .xlsx, then upload that.' );
        }
        return self::read_csv( $path );
    }

    /** Rows out of a CSV, tab or comma separated. */
    private static function read_csv( $path ) {
        $rows   = array();
        $handle = fopen( $path, 'r' );
        if ( ! $handle ) {
            return new WP_Error( 'unreadable', 'Could not read that file.' );
        }
        while ( false !== ( $line = fgets( $handle ) ) ) {
            $line = rtrim( $line, "\r\n" );
            if ( '' === trim( $line ) ) {
                continue;
            }
            $rows[] = ( false !== strpos( $line, "\t" ) ) ? explode( "\t", $line ) : str_getcsv( $line );
        }
        fclose( $handle );
        return $rows;
    }

    /** Rows out of an .xlsx. */
    private static function read_xlsx( $path ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error( 'no_zip', 'This server cannot read .xlsx files. Save the sheet as CSV and upload that instead.' );
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open( $path ) ) {
            return new WP_Error( 'bad_xlsx', 'That file could not be opened as a spreadsheet. If it was renamed to .xlsx, save it properly from Excel first.' );
        }

        // Shared strings: most writers put cell text here and leave a numeric
        // index in the cell itself.
        $shared = array();
        $ss     = $zip->getFromName( 'xl/sharedStrings.xml' );
        if ( false !== $ss ) {
            $xml = self::parse_xml( $ss );
            if ( $xml ) {
                foreach ( $xml->si as $si ) {
                    $shared[] = self::shared_string_text( $si );
                }
            }
        }

        // Resolve the first sheet through the workbook rels rather than assuming
        // sheet1.xml - Numbers and some exporters do not name it that.
        $target = self::first_sheet_path( $zip );
        $data   = $zip->getFromName( $target );
        if ( false === $data ) {
            $zip->close();
            return new WP_Error( 'no_sheet', 'That spreadsheet has no readable sheet in it.' );
        }
        $zip->close();

        $xml = self::parse_xml( $data );
        if ( ! $xml || ! isset( $xml->sheetData ) ) {
            return new WP_Error( 'bad_sheet', 'That spreadsheet could not be read.' );
        }

        $rows = array();
        foreach ( $xml->sheetData->row as $row ) {
            $cells = array();
            foreach ( $row->c as $c ) {
                $ref   = (string) $c['r'];
                $index = self::column_index( $ref );
                $type  = (string) $c['t'];

                if ( 's' === $type ) {
                    $i    = (int) $c->v;
                    $text = isset( $shared[ $i ] ) ? $shared[ $i ] : '';
                } elseif ( 'inlineStr' === $type ) {
                    $text = isset( $c->is ) ? self::shared_string_text( $c->is ) : '';
                } else {
                    // Numbers land here, including a phone typed without
                    // formatting. Render it as a plain integer rather than
                    // letting PHP produce 8.6055512340E+9.
                    $raw  = isset( $c->v ) ? (string) $c->v : '';
                    $text = ( '' !== $raw && is_numeric( $raw ) && floor( (float) $raw ) == $raw && abs( (float) $raw ) < 1e15 )
                        ? number_format( (float) $raw, 0, '.', '' )
                        : $raw;
                }

                // Fill gaps so a blank middle column does not shift the rest left.
                while ( count( $cells ) < $index ) {
                    $cells[] = '';
                }
                $cells[ $index ] = $text;
            }
            if ( count( array_filter( $cells, function ( $v ) { return '' !== trim( (string) $v ); } ) ) ) {
                $rows[] = $cells;
            }
        }
        return $rows;
    }

    /* ------------------------------------------------------------ internals */

    private static function parse_xml( $string ) {
        $prev = libxml_use_internal_errors( true );
        // Entities stay off: this file arrives by email from outside.
        $xml  = simplexml_load_string( $string, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );
        return $xml ? $xml : null;
    }

    /** Text of a shared string, joining the runs a formatted cell is split into. */
    private static function shared_string_text( $si ) {
        if ( isset( $si->t ) ) {
            return (string) $si->t;
        }
        $text = '';
        if ( isset( $si->r ) ) {
            foreach ( $si->r as $run ) {
                $text .= (string) $run->t;
            }
        }
        return $text;
    }

    /** Zero-based column number from a cell reference such as "C7". */
    private static function column_index( $ref ) {
        if ( ! preg_match( '/^([A-Z]+)/', strtoupper( $ref ), $m ) ) {
            return 0;
        }
        $n = 0;
        foreach ( str_split( $m[1] ) as $ch ) {
            $n = $n * 26 + ( ord( $ch ) - 64 );
        }
        return $n - 1;
    }

    /** Path of the first worksheet, via the workbook relationships. */
    private static function first_sheet_path( $zip ) {
        $book = $zip->getFromName( 'xl/workbook.xml' );
        $rels = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
        if ( false !== $book && false !== $rels ) {
            $bx = self::parse_xml( $book );
            $rx = self::parse_xml( $rels );
            if ( $bx && $rx && isset( $bx->sheets->sheet[0] ) ) {
                $rid = '';
                foreach ( $bx->sheets->sheet[0]->attributes( 'r', true ) as $key => $value ) {
                    if ( 'id' === $key ) {
                        $rid = (string) $value;
                    }
                }
                foreach ( $rx->Relationship as $rel ) {
                    if ( (string) $rel['Id'] === $rid ) {
                        $t = ltrim( (string) $rel['Target'], '/' );
                        return ( 0 === strpos( $t, 'xl/' ) ) ? $t : 'xl/' . $t;
                    }
                }
            }
        }
        return 'xl/worksheets/sheet1.xml';
    }

    private static function content_types() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function root_rels() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook( $sheet ) {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . esc_attr( substr( $sheet, 0, 31 ) ) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbook_rels() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    /** Two styles: a bold header, and text format for the body. */
    private static function styles() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="@"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private static function sheet_xml( $headers, $rows ) {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<cols><col min="1" max="1" width="26" customWidth="1"/>'
            . '<col min="2" max="2" width="16" customWidth="1" style="2"/>'
            . '<col min="3" max="3" width="32" customWidth="1"/></cols>'
            . '<sheetData>';

        $r = 1;
        $xml .= self::row_xml( $headers, $r++, 1 );
        if ( empty( $rows ) ) {
            $xml .= self::row_xml( array_fill( 0, max( 1, count( $headers ) ), '' ), $r++, 2 );
        }
        foreach ( $rows as $row ) {
            $xml .= self::row_xml( array_values( (array) $row ), $r++, 2 );
        }

        return $xml . '</sheetData></worksheet>';
    }

    private static function row_xml( $cells, $rowNum, $styleId ) {
        $xml = '<row r="' . $rowNum . '">';
        $col = 0;
        foreach ( $cells as $value ) {
            $ref  = self::column_letter( $col++ ) . $rowNum;
            $text = htmlspecialchars( (string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
            $xml .= '<c r="' . $ref . '" s="' . intval( $styleId ) . '" t="inlineStr"><is><t xml:space="preserve">'
                . $text . '</t></is></c>';
        }
        return $xml . '</row>';
    }

    private static function column_letter( $index ) {
        $letter = '';
        $index++;
        while ( $index > 0 ) {
            $rem    = ( $index - 1 ) % 26;
            $letter = chr( 65 + $rem ) . $letter;
            $index  = intdiv( $index - 1, 26 );
        }
        return $letter;
    }
}
