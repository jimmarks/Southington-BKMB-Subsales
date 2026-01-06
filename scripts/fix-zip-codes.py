#!/usr/bin/env python3
"""
Fix incorrect ZIP codes in SQL dump using geographic coordinates.
Assigns correct ZIP (06489, 06479, or 06444) based on lat/lng.
"""

import re
import sys
from typing import Dict, Tuple, List

# Define approximate ZIP code boundaries for Southington, CT
# Based on general knowledge of Southington ZIP coverage:
# 06489 - Central/North Southington (main area)
# 06479 - Plantsville (south/southeast)  
# 06444 - Marion (northeast area)

# These are approximate center points for each ZIP
ZIP_CENTERS = {
    '06489': (41.5987, -72.8776),  # Central Southington
    '06479': (41.5776, -72.8487),  # Plantsville (SE)
    '06444': (41.6147, -72.8321),  # Marion (NE)
}

def distance_squared(lat1: float, lng1: float, lat2: float, lng2: float) -> float:
    """Calculate squared distance between two points (faster than actual distance)."""
    return (lat1 - lat2) ** 2 + (lng1 - lng2) ** 2

def assign_zip_by_proximity(lat: float, lng: float) -> str:
    """Assign ZIP code based on proximity to known ZIP centers."""
    min_dist = float('inf')
    closest_zip = '06489'  # Default
    
    for zip_code, (center_lat, center_lng) in ZIP_CENTERS.items():
        dist = distance_squared(lat, lng, center_lat, center_lng)
        if dist < min_dist:
            min_dist = dist
            closest_zip = zip_code
    
    return closest_zip

def process_sql_file(input_file: str, output_file: str) -> Dict[str, int]:
    """Process SQL file and update ZIP codes based on coordinates."""
    stats = {'06489': 0, '06479': 0, '06444': 0, 'unchanged': 0, 'total': 0}
    
    with open(input_file, 'r', encoding='utf-8') as f:
        content = f.read()
    
    # Pattern to match INSERT statements with coordinates
    # Captures: street, house_num, unit, city, state, zip, lat, lng
    pattern = r"(\(\d+,\s*'[^']*',\s*'[^']*',\s*'[^']*',\s*'[^']*',\s*'[^']*',\s*)'(\d{5})'(,\s*)(-?\d+\.\d+)(,\s*)(-?\d+\.\d+)"
    
    def replace_zip(match):
        prefix = match.group(1)  # Everything before ZIP
        old_zip = match.group(2)  # Current ZIP
        sep1 = match.group(3)     # Separator after ZIP
        lat = float(match.group(4))  # Latitude
        sep2 = match.group(5)     # Separator after lat
        lng = float(match.group(6))  # Longitude
        
        # Assign correct ZIP based on coordinates
        new_zip = assign_zip_by_proximity(lat, lng)
        
        stats['total'] += 1
        if new_zip != old_zip:
            stats[new_zip] += 1
        else:
            stats['unchanged'] += 1
        
        return f"{prefix}'{new_zip}'{sep1}{lat}{sep2}{lng}"
    
    # Replace all ZIP codes
    updated_content = re.sub(pattern, replace_zip, content)
    
    # Write updated SQL
    with open(output_file, 'w', encoding='utf-8') as f:
        f.write(updated_content)
    
    return stats

def main():
    input_file = '/workspaces/Southington-BKMB-Subsales/wp_ss_addresses.sql'
    output_file = '/workspaces/Southington-BKMB-Subsales/wp_ss_addresses_fixed.sql'
    
    print(f"Processing {input_file}...")
    print(f"ZIP assignment based on proximity to known centers:")
    for zip_code, (lat, lng) in ZIP_CENTERS.items():
        print(f"  {zip_code}: ({lat}, {lng})")
    print()
    
    stats = process_sql_file(input_file, output_file)
    
    print(f"\n✓ Processing complete!")
    print(f"  Total addresses: {stats['total']}")
    print(f"  Assigned to 06489: {stats['06489']}")
    print(f"  Assigned to 06479: {stats['06479']}")
    print(f"  Assigned to 06444: {stats['06444']}")
    print(f"  Unchanged: {stats['unchanged']}")
    print(f"\nOutput written to: {output_file}")

if __name__ == '__main__':
    main()
