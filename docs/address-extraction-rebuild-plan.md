# Address Extraction System Rebuild - Implementation Plan

**Version:** 2.0.0.19+
**Date:** December 3, 2025
**Status:** Planning Phase

---

## 🎯 Project Goals

1. **Multi-format upload**: Support both shapefile ZIP archives and CSV files
2. **Smart matching**: Cross-reference uploaded data with Overpass API
3. **GPS enrichment**: Generate/extract coordinates for all addresses
4. **Database storage**: Store coordinates in GPS table for route optimization
5. **PWA delivery**: Push residential addresses to PWA as ZIP code JSON files
6. **Backward compatibility**: Don't break existing CSV upload functionality

---

## 📊 Current State Analysis

### ✅ Working Systems (Don't Break These!)
- **CSV Upload**: Manual address entry via Settings page
- **Overpass Generation**: `subsales_generate_zip_from_overpass()` (lines 1728-1850)
- **JSON Output**: Per-ZIP files at `wp-content/uploads/subsales-zipdata/<zip>.json`
- **PWA Integration**: Loads ZIP JSON files for offline autocomplete
- **Orders System**: References addresses without GPS (for now)

### ❌ Current Limitations
- No shapefile support
- No address matching/deduplication
- No GPS coordinate storage
- No commercial address filtering
- Manual data entry only
- No parcel data integration

### 🗄️ Database Schema (Current)

**wp_ss_orders** (has lat/lng columns but rarely populated):
```sql
CREATE TABLE wp_ss_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(255),
    phone VARCHAR(20),
    email VARCHAR(255),
    address TEXT,
    city VARCHAR(100),
    state VARCHAR(50),
    zip VARCHAR(10),
    lat DECIMAL(10, 8),      -- EXISTS but not populated
    lng DECIMAL(11, 8),      -- EXISTS but not populated
    -- ... other order fields
);
```

**Need to create**: Address/GPS lookup table for pre-validated addresses

---

## 🏗️ Phase 1: Database Schema & Infrastructure (v2.0.0.19)

### Step 1.1: Create GPS/Address Lookup Table

**Goal**: Store all validated addresses with GPS coordinates for reuse across orders and route optimization.

**New Table**: `wp_ss_addresses`
```sql
CREATE TABLE wp_ss_addresses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Address Components
    street VARCHAR(255) NOT NULL,
    house_number VARCHAR(20),
    unit VARCHAR(20),
    city VARCHAR(100) NOT NULL DEFAULT 'Southington',
    state VARCHAR(2) NOT NULL DEFAULT 'CT',
    zip VARCHAR(10) NOT NULL,
    
    -- GPS Coordinates
    lat DECIMAL(10, 8) NOT NULL,
    lng DECIMAL(11, 8) NOT NULL,
    
    -- Data Source & Quality
    source ENUM('parcel', 'overpass', 'csv', 'manual') NOT NULL,
    confidence ENUM('high', 'medium', 'low') DEFAULT 'medium',
    matched BOOLEAN DEFAULT 0,  -- Was it matched to Overpass?
    
    -- Address Type
    type ENUM('residential', 'commercial', 'other') DEFAULT 'residential',
    
    -- Full formatted address (for display)
    full_address TEXT,
    
    -- Metadata
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes for fast lookup
    INDEX idx_zip (zip),
    INDEX idx_street (street),
    INDEX idx_type (type),
    INDEX idx_source (source),
    INDEX idx_coordinates (lat, lng),
    UNIQUE KEY unique_address (street, house_number, unit, zip)
);
```

**Migration Function**: Add to activation hook to create table if not exists.

**Files to modify**:
- `wordpress-plugin/subsales-management.php` (activation hook)

---

## 🏗️ Phase 2: Admin UI for File Upload (v2.0.0.20)

### Step 2.1: Create Address Extracts Admin Page

**New Admin Menu Item**: "Address Extracts" (under BKMB Subsales)

**Page Components**:
1. **Upload Section**
   - File upload field (accepts .zip or .csv)
   - Auto-detect file type
   - Upload button
   
2. **Processing Status**
   - Progress indicator
   - Current step display
   - Error/warning messages
   
3. **Results Preview**
   - Total addresses found
   - Matched vs unmatched count
   - GPS coverage percentage
   - Sample addresses table
   
4. **Generation Controls**
   - Configure ZIP codes to generate (06479, 06489, 06467)
   - Commercial filtering checkbox
   - Generate button
   
5. **Download/Export**
   - Download merged addresses as CSV
   - View generated JSON files
   - Clear/reset data

**Files to create**:
- New admin page function `subsales_address_extracts_page()`

**Files to modify**:
- `wordpress-plugin/subsales-management.php` (add menu item, page function)

---

## 🏗️ Phase 3: Shapefile Parser (v2.0.0.21)

### Step 3.1: Choose Parser Implementation

**Option A: PHP Shapefile Library** (RECOMMENDED)
- **Library**: `gasparesganga/php-shapefile`
- **Installation**: `composer require gasparesganga/php-shapefile`
- **Pros**: Pure PHP, handles projections, well-documented
- **Cons**: Requires Composer

**Option B: GDAL/OGR Command-Line**
- **Tool**: `ogr2ogr` (convert shapefile → CSV)
- **Installation**: `apt-get install gdal-bin`
- **Pros**: Fast, battle-tested
- **Cons**: External dependency, harder to debug

**Decision**: Start with Option A (PHP library), fallback to Option B if needed.

### Step 3.2: Implement Shapefile Upload Handler

**Function**: `subsales_process_shapefile_upload($file)`

**Process**:
1. Validate ZIP contains required files (.shp, .dbf, .prj)
2. Extract to temporary directory
3. Parse .dbf for address attributes
4. Parse .shp for polygon geometries
5. Calculate centroids
6. Transform coordinates (State Plane CT → WGS84)
7. Return array of addresses with coordinates

**Output Format**:
```php
[
    [
        'street' => 'JEFFREY LN',
        'house_number' => '9',
        'unit' => '',
        'lat' => 41.5982,
        'lng' => -72.8776,
        'source' => 'parcel',
        'confidence' => 'high'
    ],
    // ... 18,333 parcels
]
```

**Files to create**:
- `wordpress-plugin/includes/shapefile-parser.php`

---

## 🏗️ Phase 4: Overpass Matching Engine (v2.0.0.22)

### Step 4.1: Enhanced Overpass Query

**Function**: `subsales_query_overpass_for_matching($zip_codes)`

**Improvements over current**:
- Query all 3 ZIP codes in one request (or batched)
- Add commercial filtering in query
- Extract more address attributes
- Return normalized addresses

**Overpass Query** (with commercial filtering):
```overpassql
[out:json][timeout:60];
(
  // Query for all 3 ZIPs
  node["addr:postcode"~"^(06479|06489|06467)$"]["addr:housenumber"]
    (41.5,-73.0,41.7,-72.7);  // Bounding box for Southington
  way["addr:postcode"~"^(06479|06489|06467)$"]["addr:housenumber"]
    (41.5,-73.0,41.7,-72.7);
);
// Filter OUT commercial tags
out body;
>;
out skel qt;
```

**Post-processing** (PHP filter for commercial):
```php
foreach ($elements as $el) {
    $tags = $el['tags'] ?? [];
    
    // Skip commercial addresses
    if (isset($tags['shop']) || isset($tags['amenity']) || isset($tags['office'])) {
        continue;
    }
    if (isset($tags['building']) && in_array($tags['building'], 
        ['commercial', 'retail', 'industrial', 'office'])) {
        continue;
    }
    
    // Keep residential
    $addresses[] = normalize_osm_address($el);
}
```

**Output Format**:
```php
[
    [
        'street' => 'MAIN ST',
        'house_number' => '123',
        'unit' => '',
        'zip' => '06479',
        'lat' => 41.5982,
        'lng' => -72.8776,
        'source' => 'overpass',
        'osm_id' => 'node/12345'
    ],
    // ... 1,500-3,000 residential addresses
]
```

### Step 4.2: Address Matching Algorithm

**Function**: `subsales_match_addresses($parcels, $overpass_addresses)`

**Matching Logic**:
```php
// Normalize addresses for comparison
function normalize_address_key($street, $house) {
    $street = strtoupper(trim($street));
    $street = preg_replace('/\s+/', ' ', $street);
    $street = str_replace(['STREET', 'ROAD', 'AVENUE', 'LANE', 'DRIVE'], 
                         ['ST', 'RD', 'AVE', 'LN', 'DR'], $street);
    $house = trim($house);
    return $house . '|' . $street;
}

// Build lookup table from Overpass
$overpass_lookup = [];
foreach ($overpass_addresses as $addr) {
    $key = normalize_address_key($addr['street'], $addr['house_number']);
    $overpass_lookup[$key] = $addr;
}

// Match parcels
$matched = [];
$unmatched = [];
foreach ($parcels as $parcel) {
    $key = normalize_address_key($parcel['street'], $parcel['house_number']);
    
    if (isset($overpass_lookup[$key])) {
        // MATCH! Use Overpass ZIP + coordinates
        $matched[] = array_merge($parcel, [
            'zip' => $overpass_lookup[$key]['zip'],
            'lat' => $overpass_lookup[$key]['lat'],
            'lng' => $overpass_lookup[$key]['lng'],
            'matched' => true,
            'confidence' => 'high',
            'source' => 'parcel+overpass'
        ]);
    } else {
        // No match - keep parcel centroid coordinates
        $unmatched[] = array_merge($parcel, [
            'matched' => false,
            'confidence' => 'medium',
            // ZIP will be assigned in next step
        ]);
    }
}

return ['matched' => $matched, 'unmatched' => $unmatched];
```

**Files to create**:
- `wordpress-plugin/includes/address-matcher.php`

---

## 🏗️ Phase 5: ZIP Assignment for Unmatched (v2.0.0.23)

### Step 5.1: ZIP Code Assignment Strategy

**For unmatched parcels (~20-30%)**, assign ZIP based on:

**Option A: Default to 06479** (Quick, simple)
```php
foreach ($unmatched as &$addr) {
    $addr['zip'] = '06479';  // Southington main ZIP
}
```

**Option B: Census ZCTA Lookup** (Accurate, complex)
- Use Census ZCTA shapefile or API
- Spatial point-in-polygon query
- More accurate but requires additional data

**Option C: Nearest Neighbor** (Smart fallback)
```php
// For each unmatched address, find nearest matched address
foreach ($unmatched as &$addr) {
    $nearest_zip = find_nearest_matched_zip($addr['lat'], $addr['lng'], $matched);
    $addr['zip'] = $nearest_zip;
    $addr['confidence'] = 'low';  // Lower confidence
}
```

**Recommendation**: Start with Option A, add Option C as enhancement.

---

## 🏗️ Phase 6: Database Storage (v2.0.0.24)

### Step 6.1: Bulk Insert Addresses

**Function**: `subsales_store_addresses($addresses)`

**Process**:
1. Clear existing addresses for affected ZIP codes
2. Prepare bulk insert data
3. Insert into `wp_ss_addresses`
4. Log statistics

**Implementation**:
```php
function subsales_store_addresses($addresses) {
    global $wpdb;
    $table = $wpdb->prefix . 'ss_addresses';
    
    // Start transaction
    $wpdb->query('START TRANSACTION');
    
    try {
        // Clear existing addresses for these ZIPs
        $zips = array_unique(array_column($addresses, 'zip'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE zip IN (" . 
            implode(',', array_fill(0, count($zips), '%s')) . ")",
            ...$zips
        ));
        
        // Bulk insert
        $values = [];
        $placeholders = [];
        foreach ($addresses as $addr) {
            $values[] = $addr['street'];
            $values[] = $addr['house_number'] ?? '';
            $values[] = $addr['unit'] ?? '';
            $values[] = $addr['city'] ?? 'Southington';
            $values[] = $addr['state'] ?? 'CT';
            $values[] = $addr['zip'];
            $values[] = $addr['lat'];
            $values[] = $addr['lng'];
            $values[] = $addr['source'];
            $values[] = $addr['confidence'] ?? 'medium';
            $values[] = $addr['matched'] ?? 0;
            $values[] = $addr['type'] ?? 'residential';
            $values[] = format_full_address($addr);
            
            $placeholders[] = "(%s, %s, %s, %s, %s, %s, %f, %f, %s, %s, %d, %s, %s)";
        }
        
        $sql = "INSERT INTO {$table} 
                (street, house_number, unit, city, state, zip, lat, lng, 
                 source, confidence, matched, type, full_address) 
                VALUES " . implode(', ', $placeholders);
        
        $wpdb->query($wpdb->prepare($sql, ...$values));
        
        $wpdb->query('COMMIT');
        
        return [
            'success' => true,
            'count' => count($addresses),
            'zips' => $zips
        ];
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        throw $e;
    }
}
```

---

## 🏗️ Phase 7: JSON Generation (v2.0.0.25)

### Step 7.1: Generate ZIP Code JSON Files

**Function**: `subsales_generate_zip_json_from_database($zip_codes)`

**Process**:
1. Query `wp_ss_addresses` for each ZIP
2. Filter to residential only
3. Format for PWA consumption
4. Write JSON files
5. Update generation metadata

**Implementation**:
```php
function subsales_generate_zip_json_from_database($zip_codes = ['06479', '06489', '06467']) {
    global $wpdb;
    $table = $wpdb->prefix . 'ss_addresses';
    $base_dir = wp_upload_dir()['basedir'] . '/subsales-zipdata';
    
    // Ensure directory exists
    if (!file_exists($base_dir)) {
        wp_mkdir_p($base_dir);
    }
    
    $results = [];
    
    foreach ($zip_codes as $zip) {
        // Query residential addresses for this ZIP
        $addresses = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE zip = %s 
             AND type = 'residential'
             ORDER BY street, house_number",
            $zip
        ), ARRAY_A);
        
        // Format for PWA
        $formatted = [];
        foreach ($addresses as $addr) {
            $formatted[] = [
                'id' => $addr['id'],
                'label' => $addr['full_address'],
                'street' => $addr['street'],
                'housenumber' => $addr['house_number'],
                'unit' => $addr['unit'],
                'city' => $addr['city'],
                'state' => $addr['state'],
                'zip' => $addr['zip'],
                'lat' => (float)$addr['lat'],
                'lng' => (float)$addr['lng']
            ];
        }
        
        // Write JSON file
        $json_data = [
            'addresses' => $formatted,
            'total' => count($formatted),
            'zip' => $zip,
            'generated_at' => current_time('mysql'),
            'source' => 'database'
        ];
        
        $file_path = $base_dir . '/' . $zip . '.json';
        file_put_contents($file_path, json_encode($json_data, JSON_PRETTY_PRINT));
        
        $results[$zip] = [
            'count' => count($formatted),
            'file' => $file_path
        ];
    }
    
    return $results;
}
```

---

## 🏗️ Phase 8: CSV Upload Compatibility (v2.0.0.26)

### Step 8.1: Enhance CSV Import

**Current CSV format** (Settings page):
```csv
street,city,state,zip
123 Main St,Southington,CT,06479
```

**Enhanced CSV format** (with GPS):
```csv
street,city,state,zip,lat,lng
123 Main St,Southington,CT,06479,41.5982,-72.8776
```

**Function**: `subsales_process_csv_upload($file)`

**Process**:
1. Parse CSV (existing logic)
2. If lat/lng columns exist, use them
3. If missing, attempt geocoding (Google Maps API or skip)
4. Insert into `wp_ss_addresses` with `source='csv'`
5. Generate ZIP JSON files

**Backward compatibility**: If uploaded CSV has no lat/lng, still accept it but mark confidence as 'low' and coordinates as NULL (to be filled later).

---

## 🏗️ Phase 9: Admin UI Enhancements (v2.0.0.27)

### Step 9.1: Address Extracts Dashboard

**Dashboard Sections**:

1. **Data Summary**
   ```
   📊 Address Database Summary
   ├─ Total Addresses: 18,456
   ├─ Residential: 17,890
   ├─ Commercial: 566
   ├─ With GPS: 18,456 (100%)
   ├─ High Confidence: 15,234 (85%)
   └─ Last Updated: Dec 3, 2025 2:30 PM
   ```

2. **ZIP Code Breakdown**
   ```
   06479 (Southington): 12,345 addresses
   06489 (Plantsville): 4,567 addresses
   06467 (Milldale): 1,544 addresses
   ```

3. **Data Sources**
   ```
   Parcel + Overpass Match: 12,345 (67%)
   Parcel Only: 5,678 (31%)
   CSV Import: 234 (1%)
   Manual Entry: 199 (1%)
   ```

4. **Action Buttons**
   - 📤 Upload Shapefile
   - 📄 Upload CSV
   - 🔄 Regenerate JSON Files
   - 📥 Export All Addresses
   - 🗑️ Clear Address Database

### Step 9.2: Processing Progress Indicator

**AJAX-based progress tracking**:
```php
// Server-side progress tracking
set_transient('subsales_processing_progress', [
    'step' => 'parsing_shapefile',
    'percent' => 25,
    'message' => 'Parsing shapefile (4,567 / 18,333 parcels)...'
], 300);

// Client-side polling
setInterval(function() {
    $.get(ajaxurl, {action: 'subsales_get_progress'}, function(data) {
        $('#progress-bar').css('width', data.percent + '%');
        $('#progress-message').text(data.message);
    });
}, 1000);
```

---

## 🏗️ Phase 10: PWA Integration (v2.0.1.0)

### Step 10.1: No Changes Needed!

**Good news**: The PWA already consumes JSON files from `subsales-zipdata/`, so no PWA changes required initially.

**Current PWA behavior**:
- Loads `06479.json`, `06489.json`, `06467.json`
- Caches in IndexedDB
- Provides autocomplete suggestions
- Uses `lat`/`lng` if available

**What changes automatically**:
- ✅ More addresses available (18,000 vs 2,000)
- ✅ GPS coordinates now present (100% coverage)
- ✅ Better address quality (deduplicated, matched)
- ✅ Residential only (commercial filtered)

### Step 10.2: Future PWA Enhancements (Optional)

1. **Route Optimization**
   - Use GPS coordinates to calculate distances
   - Suggest optimal delivery sequence
   - Display on map

2. **Address Validation**
   - Check entered address against database
   - Suggest closest match if not exact
   - Pre-fill GPS coordinates

3. **Offline Maps**
   - Cache map tiles for Southington
   - Display delivery route without internet

---

## 📋 Implementation Checklist

### Phase 1: Database ✅ Ready to Code
- [ ] Create `wp_ss_addresses` table schema
- [ ] Add table creation to activation hook
- [ ] Test table creation on fresh install
- [ ] Add migration for existing installs

### Phase 2: Admin UI ✅ Ready to Code
- [ ] Create "Address Extracts" menu item
- [ ] Build upload form (shapefile + CSV)
- [ ] Add file type detection
- [ ] Create processing status UI
- [ ] Add results preview table

### Phase 3: Shapefile Parser ⚠️ Needs Composer
- [ ] Install `gasparesganga/php-shapefile` via Composer
- [ ] Create `shapefile-parser.php`
- [ ] Implement ZIP extraction
- [ ] Parse .dbf attributes
- [ ] Parse .shp geometries
- [ ] Calculate centroids
- [ ] Transform coordinates (State Plane → WGS84)
- [ ] Test with CTPARCEL_Southington.zip

### Phase 4: Overpass Matching ✅ Ready to Code
- [ ] Enhance Overpass query for 3 ZIPs
- [ ] Add commercial filtering
- [ ] Create `address-matcher.php`
- [ ] Implement address normalization
- [ ] Build lookup table
- [ ] Match parcels to Overpass
- [ ] Track matched/unmatched counts

### Phase 5: ZIP Assignment ✅ Ready to Code
- [ ] Implement default ZIP assignment (06479)
- [ ] Add confidence scoring
- [ ] (Optional) Add nearest neighbor lookup

### Phase 6: Database Storage ✅ Ready to Code
- [ ] Implement bulk insert function
- [ ] Add transaction handling
- [ ] Create address deduplication logic
- [ ] Add logging/statistics

### Phase 7: JSON Generation ✅ Ready to Code
- [ ] Query database by ZIP
- [ ] Format for PWA consumption
- [ ] Write JSON files
- [ ] Update generation metadata

### Phase 8: CSV Compatibility ✅ Ready to Code
- [ ] Enhance CSV parser for lat/lng
- [ ] Add geocoding fallback (optional)
- [ ] Insert CSV data into database
- [ ] Maintain backward compatibility

### Phase 9: Admin Enhancements ✅ Ready to Code
- [ ] Build data summary dashboard
- [ ] Add ZIP breakdown stats
- [ ] Create source breakdown
- [ ] Implement AJAX progress tracking
- [ ] Add export functionality

### Phase 10: PWA Integration ✅ No Changes Needed
- [ ] Test existing PWA with new JSON files
- [ ] Verify GPS coordinates work
- [ ] Validate autocomplete performance
- [ ] (Optional) Add route optimization

---

## 🧪 Testing Strategy

### Unit Tests
- [ ] Address normalization function
- [ ] Coordinate transformation
- [ ] Matching algorithm accuracy

### Integration Tests
- [ ] Shapefile upload → database → JSON
- [ ] CSV upload → database → JSON
- [ ] Overpass query → matching → storage

### End-to-End Tests
1. Upload CTPARCEL_Southington.zip
2. Verify 18,333 addresses loaded
3. Check matching rate (expect 70-80%)
4. Verify GPS coordinates (expect 100%)
5. Generate JSON files
6. Test PWA autocomplete
7. Verify route optimization works

### Performance Tests
- [ ] 18,000+ address bulk insert time
- [ ] JSON generation time
- [ ] PWA load time with large dataset
- [ ] Memory usage during shapefile parsing

---

## 🚨 Risk Mitigation

### Risks & Mitigation Strategies

**Risk 1: Breaking existing CSV upload**
- **Mitigation**: Keep existing code path, add new shapefile path separately
- **Test**: Upload old-format CSV, verify it still works

**Risk 2: Shapefile parsing fails**
- **Mitigation**: Add robust error handling, fallback to manual entry
- **Test**: Upload corrupted shapefile, verify graceful failure

**Risk 3: Overpass API rate limits**
- **Mitigation**: Add retry logic, exponential backoff, caching
- **Test**: Query multiple times rapidly, verify 429 handling

**Risk 4: Database performance with 18,000+ addresses**
- **Mitigation**: Add indexes, use bulk inserts, optimize queries
- **Test**: Query performance with full dataset

**Risk 5: PWA breaks with large JSON files**
- **Mitigation**: Keep existing JSON format, test with large datasets
- **Test**: Load 18,000 addresses in PWA, measure performance

**Risk 6: Memory exhaustion during processing**
- **Mitigation**: Process in batches, increase PHP memory limit
- **Test**: Monitor memory usage during shapefile parsing

---

## 📦 Deliverables by Phase

### v2.0.0.19: Database Schema
- New `wp_ss_addresses` table
- Migration script
- Documentation

### v2.0.0.20: Admin UI
- Address Extracts admin page
- File upload form
- Basic UI components

### v2.0.0.21: Shapefile Parser
- Composer dependency
- Shapefile parsing library
- Coordinate transformation

### v2.0.0.22: Matching Engine
- Overpass query enhancements
- Address matching algorithm
- Match statistics

### v2.0.0.23: ZIP Assignment
- Unmatched address handling
- Confidence scoring

### v2.0.0.24: Database Storage
- Bulk insert implementation
- Address deduplication

### v2.0.0.25: JSON Generation
- Database-driven JSON files
- PWA-compatible format

### v2.0.0.26: CSV Compatibility
- Enhanced CSV parser
- Backward compatibility

### v2.0.0.27: Admin Enhancements
- Dashboard statistics
- Progress tracking
- Export functionality

### v2.0.1.0: PWA Testing
- Validation with new dataset
- Performance testing
- Route optimization demo

---

## ⏱️ Estimated Timeline

**Total: 8-10 development sessions** (assuming 2-3 hours per session)

- Phase 1: 1 session (database schema)
- Phase 2: 1 session (admin UI)
- Phase 3: 2 sessions (shapefile parser - complex)
- Phase 4: 1 session (matching engine)
- Phase 5: 0.5 session (ZIP assignment)
- Phase 6: 1 session (database storage)
- Phase 7: 0.5 session (JSON generation)
- Phase 8: 0.5 session (CSV compatibility)
- Phase 9: 1.5 sessions (admin enhancements)
- Phase 10: 1 session (PWA testing & optimization)

---

## 🎬 Next Steps

**Immediate action items**:

1. **Review this plan** - Does it cover your requirements?
2. **Approve Phase 1** - Database schema design
3. **Start coding** - Begin with database table creation
4. **Test incrementally** - Each phase builds on the previous

**Questions to answer before coding**:
- [ ] Should we use Composer for PHP shapefile library?
- [ ] What's acceptable processing time for 18,333 parcels?
- [ ] Should we add geocoding for CSV imports without GPS?
- [ ] Do we need admin permission levels for address management?

**Let me know when you're ready to begin Phase 1!** 🚀
