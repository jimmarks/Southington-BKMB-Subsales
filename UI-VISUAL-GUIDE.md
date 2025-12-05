# Address Management UI - Visual Guide

## Dashboard Layout

```
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│  WordPress Admin > Settings > BKMB Subsales                                            │
├─────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                         │
│  📍 Address Management                                                                  │
│  Manage your service area configuration, upload address data, and generate JSON        │
│  extracts for the PWA.                                                                 │
│                                                                                         │
│  ┌───────────────────────┬───────────────────────┬───────────────────────┐            │
│  │ ⚙️ Configuration       │ 📤 Upload & Data       │ 🔧 Process & Export    │            │
│  ├───────────────────────┼───────────────────────┼───────────────────────┤            │
│  │ Service area setup    │ Address database      │ Generate JSON extracts│            │
│  ├───────────────────────┼───────────────────────┼───────────────────────┤            │
│  │                       │                       │                       │            │
│  │ ✓ Configured          │ ✓ Loaded              │ ● In Progress         │            │
│  │                       │                       │                       │            │
│  │ ZIP Codes: 3          │ Total Addresses:      │ Matched to OSM:       │            │
│  │                       │ 18,333                │ 11,916 (65%)          │            │
│  │ ┌────┬────┬────┐      │                       │                       │            │
│  │ │06479│06489│06467│      │ Residential: 18,333   │ Matching Progress     │            │
│  │ └────┴────┴────┘      │                       │ ████████████░░░░ 65%  │            │
│  │                       │ With ZIP Codes:       │                       │            │
│  │                       │ 15,234 (83%)          │ Generated Extracts:   │            │
│  │                       │                       │ 3 files               │            │
│  │                       │ ZIP Assignment        │                       │            │
│  │ ┌──────────────────┐  │ █████████████░░░ 83%  │                       │            │
│  │ │ ⚙️ Edit ZIP Codes │  │                       │ ┌──────────────────┐  │            │
│  │ └──────────────────┘  │ ┌──────────────────┐  │ │ Match w/ Overpass│  │            │
│  │                       │ │ 📤 Upload File    │  │ └──────────────────┘  │            │
│  │                       │ └──────────────────┘  │ ┌──────────────────┐  │            │
│  │                       │                       │ │ Generate Extracts│  │            │
│  │                       │                       │ └──────────────────┘  │            │
│  │                       │                       │ ┌──────────────────┐  │            │
│  │                       │                       │ │ 📋 View Extracts  │  │            │
│  │                       │                       │ └──────────────────┘  │            │
│  └───────────────────────┴───────────────────────┴───────────────────────┘            │
│                                                                                         │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

## Color Scheme

### Card Headers (Gradients)

**Configuration Card:**
```
┌─────────────────────────────────────┐
│ ⚙️ Configuration                    │ ← Pink-Purple Gradient
│    Service area setup               │   #f093fb → #f5576c
└─────────────────────────────────────┘
```

**Upload & Data Card:**
```
┌─────────────────────────────────────┐
│ 📤 Upload & Data                    │ ← Blue-Cyan Gradient
│    Address database                 │   #4facfe → #00f2fe
└─────────────────────────────────────┘
```

**Process & Export Card:**
```
┌─────────────────────────────────────┐
│ 🔧 Process & Export                 │ ← Green-Teal Gradient
│    Generate JSON extracts           │   #43e97b → #38f9d7
└─────────────────────────────────────┘
```

### Status Badges

```
┌──────────────┐   ┌──────────────┐   ┌──────────────┐   ┌──────────────┐
│ ○ Ready      │   │ ● In Progress│   │ ✓ Complete   │   │ ⚠️ Warning   │
└──────────────┘   └──────────────┘   └──────────────┘   └──────────────┘
   Gray               Blue               Green              Orange
   #6b7280            #2563eb            #059669            #d97706
```

## Empty States

### No ZIP Codes
```
┌───────────────────────────────────────┐
│                                       │
│              🗺️                        │
│                                       │
│         No ZIP Codes                  │
│                                       │
│  Configure your service area to       │
│  enable address management.           │
│                                       │
└───────────────────────────────────────┘
```

### No Addresses
```
┌───────────────────────────────────────┐
│                                       │
│              📁                        │
│                                       │
│         No Addresses                  │
│                                       │
│  Upload a shapefile or CSV to         │
│  get started.                         │
│                                       │
└───────────────────────────────────────┘
```

### No Extracts
```
┌───────────────────────────────────────┐
│                                       │
│              📁                        │
│                                       │
│    No Extracts Generated              │
│                                       │
│  Click "Generate Extracts" to         │
│  create JSON files from your          │
│  address database.                    │
│                                       │
└───────────────────────────────────────┘
```

## Modal Components

### ZIP Configuration Modal
```
┌─────────────────────────────────────────────┐
│  ⚙️ Configure Service Area                  │
│                                             │
│  ZIP Codes:                                 │
│  ┌─────────────────────────────────────┐   │
│  │ 06479, 06489, 06467                 │   │
│  └─────────────────────────────────────┘   │
│                                             │
│  Enter comma-separated 5-digit ZIP codes.  │
│                                             │
│                      ┌────────┬──────────┐  │
│                      │ Cancel │ Save ZIPs│  │
│                      └────────┴──────────┘  │
└─────────────────────────────────────────────┘
```

### Upload Modal
```
┌─────────────────────────────────────────────┐
│  📤 Upload Address Data                     │
│                                             │
│  Select File:                               │
│  ┌─────────────────────────────────────┐   │
│  │ [Choose File] No file chosen        │   │
│  └─────────────────────────────────────┘   │
│                                             │
│  Supported formats:                         │
│  • .zip - Shapefile archive (.shp, .dbf)   │
│  • .csv - Address list with columns:       │
│    street, city, state, zip, lat, lng      │
│                                             │
│                      ┌────────┬──────────┐  │
│                      │ Cancel │  Upload  │  │
│                      └────────┴──────────┘  │
└─────────────────────────────────────────────┘
```

### Extracts Drawer (Slide-in from right)
```
                        ┌──────────────────────────────────────┐
                        │ 📋 Generated Extracts            [×] │
                        ├──────────────────────────────────────┤
                        │                                      │
                        │ ZIP Code  File Size  Actions         │
                        │ ────────────────────────────────     │
                        │ 06479     234 KB 📄   [Delete]       │
                        │ 06489     189 KB 📄   [Delete]       │
                        │ 06467     201 KB 📄   [Delete]       │
                        │                                      │
                        │ ▶ 📜 Generation History              │
                        │                                      │
                        │   (Click to expand last 5 runs)      │
                        │                                      │
                        └──────────────────────────────────────┘
```

### Generation History (Expanded)
```
▼ 📜 Generation History
┌─────────────────────────────────────────────┐
│  2024-12-04 14:23:15                        │
│  18,333 addresses in 4.2s                   │
└─────────────────────────────────────────────┘
┌─────────────────────────────────────────────┐
│  2024-12-04 12:15:03                        │
│  18,200 addresses in 4.1s                   │
└─────────────────────────────────────────────┘
┌─────────────────────────────────────────────┐
│  2024-12-03 09:45:22                        │
│  15,123 addresses in 3.8s                   │
└─────────────────────────────────────────────┘
```

## Progress Bars

### Filled (83%)
```
ZIP Assignment
████████████████████████████████████░░░░░░░░░ 83%
```

### Partial (65%)
```
Matching Progress
██████████████████████████████░░░░░░░░░░░░░░░ 65%
```

### Complete (100%)
```
Matching Progress
█████████████████████████████████████████████ 100%
```

## Responsive Breakpoints

### Desktop (>1200px) - 3 Columns
```
┌────────────┬────────────┬────────────┐
│ Config     │ Upload     │ Process    │
│            │            │            │
└────────────┴────────────┴────────────┘
```

### Tablet (768px-1200px) - 2 Columns
```
┌────────────┬────────────┐
│ Config     │ Upload     │
│            │            │
├────────────┴────────────┤
│ Process                 │
│                         │
└─────────────────────────┘
```

### Mobile (<768px) - 1 Column
```
┌─────────────────────────┐
│ Config                  │
│                         │
├─────────────────────────┤
│ Upload                  │
│                         │
├─────────────────────────┤
│ Process                 │
│                         │
└─────────────────────────┘
```

## Card Hover Effects

**Normal State:**
```
┌───────────────────────┐
│ ⚙️ Configuration       │
│ Service area setup    │
├───────────────────────┤
│                       │
│ Content...            │
│                       │
└───────────────────────┘
```

**Hover State:** (subtle lift + shadow)
```
  ┌───────────────────────┐
  │ ⚙️ Configuration       │
  │ Service area setup    │ ← Lift: -4px
  ├───────────────────────┤    Shadow: 0 10px 30px rgba(0,0,0,0.08)
  │                       │
  │ Content...            │
  │                       │
  └───────────────────────┘
```

## Typography

**Card Headers:**
- Title: 18px, bold, white
- Subtitle: 14px, semi-bold, rgba(255,255,255,0.9)

**Stat Labels:**
- Font: 13px, normal, #646970
- Margin: 0 0 4px 0

**Stat Values:**
- Font: 15px, bold, #1e293b
- Margin: 0 0 12px 0

**Status Badges:**
- Font: 13px, semi-bold
- Padding: 6px 12px
- Border-radius: 12px

**Empty State:**
- Icon: 48px emoji
- Heading: 18px, bold, #1e293b
- Description: 14px, normal, #64748b

## Animation Timing

**Card Hover:**
- Transition: 0.2s ease-out
- Properties: transform, box-shadow

**Modal Open:**
- Overlay: fade-in 0.2s
- Modal: slide-up + fade-in 0.3s

**Progress Bars:**
- Fill: transition 0.4s ease-out
- Color: #2563eb (blue)

**Status Badge Change:**
- Transition: background-color 0.3s, color 0.3s

## Accessibility

**Keyboard Navigation:**
- All buttons: tab-focusable
- Modals: trap focus, Esc to close
- Buttons: Enter/Space to activate

**Screen Readers:**
- Cards: `<section>` with aria-label
- Status badges: aria-live="polite"
- Progress bars: aria-valuenow, aria-valuemin, aria-valuemax
- Empty states: role="status"

**Color Contrast:**
- Status badge text: WCAG AA compliant
- Progress bar: minimum 3:1 contrast
- Link text: underline on focus

---

**Note:** This is a text-based mockup. Actual implementation includes full CSS styling, gradients, shadows, and smooth transitions as specified in `admin-dashboard.css`.
