# v2.0.0.26 Deployment Checklist

## 📦 Pre-Deployment

- [x] Plugin packaged: `subsales-management.zip` (4.0M)
- [x] Version bumped: `2.0.0.25` → `2.0.0.26`
- [x] CHANGELOG.md updated with release notes
- [x] Release notes created: `v2.0.0.26-RELEASE-NOTES.md`
- [x] No PHP errors detected in modified files
- [x] Backward compatibility maintained (defaults to Southington ZIPs)

## 🎯 What Changed

### Core Functionality
- ✅ Removed all hardcoded Southington, CT location logic
- ✅ Added dynamic ZIP code configuration UI (Settings → Service Area)
- ✅ Integrated Google Maps API for geocoding
- ✅ Implemented 7-day caching for bounding boxes
- ✅ Added location-agnostic Overpass matching

### Files Modified
1. **subsales-management.php** (~120 lines added)
   - Version: Line 2
   - ZIP save handler: Lines 6904-6921
   - Navigation: Line 6971
   - Service Area panel: Lines 7258-7368

2. **includes/overpass-matcher.php** (~140 lines refactored)
   - Removed hardcoded constants
   - Added dynamic ZIP methods
   - Google Maps API integration
   - City-agnostic Overpass queries

## 📋 Deployment Steps

### For Existing Southington Installation

1. **Backup Current Installation**
   ```bash
   # In WordPress admin
   BKMB Subsales → Settings → Backup / Restore → Backup Database
   ```

2. **Upload Plugin**
   - Go to Plugins → Add New → Upload Plugin
   - Select `subsales-management.zip`
   - Click "Install Now"
   - Activate plugin

3. **Verify Default Configuration**
   - Navigate to `BKMB Subsales → Settings → Service Area`
   - Confirm ZIPs show: `06479, 06489, 06467`
   - Check Google Maps API status (should be green if key configured)

4. **Test Address Matching**
   - Upload a small test shapefile (Address Extracts)
   - Verify Overpass matching still works
   - Check ZIP assignment is accurate

5. **Monitor for Issues**
   - Check WordPress error logs
   - Verify no JavaScript console errors
   - Test mobile PWA address autocomplete

### For New Location (e.g., Hartford, CT)

1. **Install Plugin** (same as above)

2. **Configure Google Maps API**
   - `Settings → Overall Settings`
   - Enter/verify Google Maps API key
   - Enable Geocoding API in Google Cloud Console

3. **Configure Service Area**
   - `Settings → Service Area`
   - Enter ZIPs: `06106, 06103, 06105` (example Hartford)
   - Click "Save ZIP Codes"
   - Verify green checkmark for API status

4. **Upload Local Shapefiles**
   - `Address Extracts → Upload shapefile`
   - Select Hartford area shapefile
   - Process addresses

5. **Verify Matching**
   - Check Address Extracts statistics
   - Verify addresses matched to Hartford ZIPs
   - Test Overpass matching accuracy

## ✅ Post-Deployment Verification

### Functional Tests
- [ ] ZIP configuration UI displays correctly
- [ ] ZIP codes save successfully
- [ ] Google Maps API status indicator works
- [ ] Bounding box cached in transients (check `wp_options`)
- [ ] Overpass matching uses configured ZIPs
- [ ] ZIP assignment accurate for uploaded addresses
- [ ] Mobile PWA address autocomplete works

### Performance Tests
- [ ] Check Google Maps API usage in Cloud Console
- [ ] Verify transient caching working (no repeated geocodes)
- [ ] Monitor API costs (should be <$5/month)
- [ ] Test with 500+ address shapefile

### Backward Compatibility
- [ ] Existing Southington ZIPs auto-populate
- [ ] Old `subsales_served_zips` option still works
- [ ] Fallback logic works without API key
- [ ] No errors in WordPress debug log

## 🐛 Troubleshooting

### Issue: ZIP codes not saving
**Solution:** Check WordPress user permissions, verify nonce validation

### Issue: API key shows red warning
**Solution:**
1. Verify key is configured in `Overall Settings`
2. Check Geocoding API is enabled in Google Cloud Console
3. Verify API key has proper domain restrictions

### Issue: Addresses not matching
**Solution:**
1. Check configured ZIPs match shapefile area
2. Verify bounding box cached (`SELECT * FROM wp_options WHERE option_name LIKE '%subsales_bbox%'`)
3. Test Google Maps API manually: `https://maps.googleapis.com/maps/api/geocode/json?address=06479&key=YOUR_KEY`

### Issue: High API costs
**Solution:**
1. Verify caching is working (check transient expiration)
2. Reduce shapefile upload frequency
3. Consider increasing cache duration (currently 7 days)

## 📊 Monitoring

### Google Cloud Console
- Monitor Geocoding API usage: https://console.cloud.google.com/apis/dashboard
- Expected: ~10-50 requests per day (depending on upload frequency)
- Alert if >1000 requests/day (indicates caching issue)

### WordPress Database
```sql
-- Check cached bounding boxes
SELECT * FROM wp_options WHERE option_name LIKE '%subsales_bbox%';

-- Check configured ZIPs
SELECT option_value FROM wp_options WHERE option_name = 'subsales_served_zipcodes';

-- Check address count by ZIP
SELECT zip, COUNT(*) FROM wp_ss_addresses GROUP BY zip;
```

### Error Logs
```bash
# WordPress debug.log
tail -f /path/to/wordpress/wp-content/debug.log | grep subsales

# PHP error log
tail -f /var/log/php-fpm/error.log | grep subsales
```

## 📞 Support

### User Confusion: "Where did my ZIPs go?"
**Answer:** Navigate to Settings → Service Area. Your ZIPs should auto-populate from the old configuration.

### User Question: "Do I need Google Maps API?"
**Answer:** Yes, for accurate geocoding. The system has fallbacks but accuracy suffers without API.

### User Question: "Can I use this in [other city]?"
**Answer:** Yes! That's exactly what v2.0.0.26 enables. Just configure your ZIPs in Service Area.

## 🎉 Success Criteria

**v2.0.0.26 deployment is successful when:**
- ✅ Plugin activates without errors
- ✅ ZIP configuration UI accessible and functional
- ✅ Google Maps API integration working (green status)
- ✅ Addresses match correctly for configured area
- ✅ Bounding box cached (check transients)
- ✅ API costs <$5/month
- ✅ Mobile PWA address autocomplete works
- ✅ No WordPress errors in debug log

## 📅 Rollback Plan

**If deployment fails:**

1. **Deactivate Plugin**
   - WordPress Admin → Plugins → Deactivate "BKMB Subsales Management"

2. **Restore v2.0.0.25**
   - Upload previous version ZIP
   - Activate

3. **Restore Database** (if needed)
   - BKMB Subsales → Settings → Backup / Restore → Restore Database
   - Select backup from before upgrade

4. **Report Issue**
   - Document error messages
   - Check `debug.log` for PHP errors
   - Test with minimal plugins (rule out conflicts)

## 📝 Notes

**File Locations:**
- Plugin ZIP: `/workspaces/Southington-BKMB-Subsales/subsales-management.zip`
- Release Notes: `/workspaces/Southington-BKMB-Subsales/v2.0.0.26-RELEASE-NOTES.md`
- CHANGELOG: `/workspaces/Southington-BKMB-Subsales/wordpress-plugin/CHANGELOG.md`

**Key Contacts:**
- WordPress Admin: [Your WP admin login]
- Google Cloud Project: [Your GCP project ID]
- API Key Location: WordPress Options → `order_sync_google_maps_api_key`

**Deployment Date:** December 3, 2024  
**Deployed By:** [Your name]  
**Environment:** [Production/Staging]

---

**End of Checklist** - Good luck with deployment! 🚀
