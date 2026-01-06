# Address Database Import Instructions

## Files Created

1. **wp_ss_addresses_fixed.sql** (3.6 MB) - Ready to import with correct ZIP codes
2. **wp_ss_addresses_original.sql** (3.7 MB) - Backup of original file (all addresses had 06489)

## ZIP Code Distribution

The fixed SQL file contains 18,186 addresses distributed geographically:

- **06489** (Central Southington): 12,888 addresses
- **06479** (Plantsville): 2,682 addresses  
- **06444** (Marion): 2,616 addresses

ZIP codes were assigned based on geographic proximity to known ZIP center points using the lat/lng coordinates in the original file.

## Import Options

### Option 1: Import via MySQL Command Line

```bash
# Navigate to the plugin directory on your WordPress server
cd /path/to/wordpress/wp-content/uploads/

# Upload wp_ss_addresses_fixed.sql to the server
# Then import:
mysql -u your_username -p your_database_name < wp_ss_addresses_fixed.sql
```

### Option 2: Import via phpMyAdmin

1. Log into phpMyAdmin
2. Select your WordPress database
3. Click the "Import" tab
4. Choose file: `wp_ss_addresses_fixed.sql`
5. Click "Go"

**Note**: The SQL file includes `DROP TABLE IF EXISTS wp_ss_addresses` so it will replace the existing table completely.

### Option 3: Import via WP-CLI (if available)

```bash
wp db import wp_ss_addresses_fixed.sql
```

## What the Import Does

The SQL file will:

1. **Drop** the existing `wp_ss_addresses` table (if it exists)
2. **Create** a new `wp_ss_addresses` table with proper schema
3. **Insert** all 18,186 addresses with corrected ZIP codes

## Verification After Import

After importing, verify the data in WordPress admin:

1. Go to **Subsales → Settings → Address Management**
2. Check the "Process & Export" section
3. You should see addresses distributed across all three ZIP codes:
   - Export 06489: ~12,888 addresses
   - Export 06479: ~2,682 addresses  
   - Export 06444: ~2,616 addresses

## Geographic Assignment Logic

ZIP codes were assigned using a proximity-based algorithm:

- **06444 (Marion)**: Centered at (41.6147, -72.8321) - Northeast Southington
- **06489 (Central)**: Centered at (41.5987, -72.8776) - Central/West Southington (largest area)
- **06479 (Plantsville)**: Centered at (41.5776, -72.8487) - Southeast Southington

Each address was assigned to the ZIP whose center point was geographically closest to the address's lat/lng coordinates.

## Rollback

If you need to rollback to the original data (all addresses with 06489):

```bash
mysql -u your_username -p your_database_name < wp_ss_addresses_original.sql
```

## Next Steps

After importing:

1. Test address autocomplete in the PWA
2. Generate delivery manifests to verify addresses are correctly grouped by ZIP
3. Consider uploading Census ZCTA boundaries (see Address Management dashboard) for even more accurate ZIP assignment in future shapefile uploads
