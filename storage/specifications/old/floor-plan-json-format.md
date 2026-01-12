# Floor Plan JSON Export/Import Format

## Overview
This document describes the JSON format used for exporting and importing warehouse floor plan layouts in the Smart WMS system.

## Features
- **Code-based references**: Uses warehouse/floor codes instead of database IDs for portability
- **Complete layout**: Includes canvas size, colors, text styles, zones, walls, and fixed areas
- **Cross-environment compatibility**: Can import layouts from different environments/databases

## JSON Structure

```json
{
  "warehouse_code": "WH001",
  "warehouse_name": "Main Warehouse",
  "floor_code": "F1",
  "floor_name": "1st Floor",
  "canvas": {
    "width": 2000,
    "height": 1500
  },
  "colors": {
    "location": {
      "border": "#D1D5DB",
      "rectangle": "#E0F2FE"
    },
    "wall": {
      "border": "#6B7280",
      "rectangle": "#9CA3AF"
    },
    "fixed_area": {
      "border": "#F59E0B",
      "rectangle": "#FEF3C7"
    }
  },
  "text_styles": {
    "location": {
      "color": "#6B7280",
      "size": 12
    },
    "wall": {
      "color": "#FFFFFF",
      "size": 10
    },
    "fixed_area": {
      "color": "#92400E",
      "size": 12
    }
  },
  "zones": [
    {
      "code1": "A",
      "code2": "001",
      "name": "Zone A-001",
      "x1_pos": 100,
      "y1_pos": 100,
      "x2_pos": 160,
      "y2_pos": 140,
      "available_quantity_flags": 3,
      "levels": 4
    }
  ],
  "walls": [
    {
      "id": 1,
      "name": "柱1",
      "x1": 300,
      "y1": 300,
      "x2": 350,
      "y2": 350
    }
  ],
  "fixed_areas": [
    {
      "id": 1,
      "name": "エレベーター",
      "x1": 500,
      "y1": 500,
      "x2": 600,
      "y2": 600
    }
  ],
  "exported_at": "2025-11-11T10:30:00+09:00"
}
```

## Field Descriptions

### Root Level
- `warehouse_code` (string): Warehouse code for reference
- `warehouse_name` (string): Warehouse name for reference
- `floor_code` (string): Floor code for reference
- `floor_name` (string): Floor name for reference
- `exported_at` (ISO 8601 string): Export timestamp

### Canvas
- `width` (integer): Canvas width in pixels (500-10000)
- `height` (integer): Canvas height in pixels (500-10000)

### Colors
Color configuration for different object types:
- `border` (hex color): Border color
- `rectangle` (hex color): Fill color

### Text Styles
Text styling for different object types:
- `color` (hex color): Text color
- `size` (integer): Font size in pixels

### Zones (Locations)
- `code1` (string): Aisle code (通路)
- `code2` (string): Shelf code (棚)
- `name` (string): Zone name
- `x1_pos`, `y1_pos` (integer): Top-left position in pixels
- `x2_pos`, `y2_pos` (integer): Bottom-right position in pixels
- `available_quantity_flags` (integer): Allocation type (1=ケース, 2=バラ, 3=ケース+バラ, 4=ボール)
- `levels` (integer): Number of shelf levels (1-4)

### Walls
- `id` (integer): Wall ID (local to layout)
- `name` (string): Wall/column name
- `x1`, `y1` (integer): Top-left position in pixels
- `x2`, `y2` (integer): Bottom-right position in pixels

### Fixed Areas
- `id` (integer): Fixed area ID (local to layout)
- `name` (string): Fixed area name (エレベーター, 荷下ろし場, etc.)
- `x1`, `y1` (integer): Top-left position in pixels
- `x2`, `y2` (integer): Bottom-right position in pixels

## Import Behavior

### Warehouse/Floor Selection
- Must select target warehouse and floor before importing
- Warehouse/floor codes in JSON are for reference only

### Zone Import
- Uses `code1` + `code2` to match existing locations
- Creates new locations if no match found
- Updates position and properties if match found

### Layout Settings
- Imports canvas size directly
- Imports colors and text styles
- Imports walls and fixed areas (replaces existing)

### Database Updates
- Updates `locations` table with new positions
- Updates `wms_warehouse_layouts` table with layout settings

## Export File Naming
Format: `layout_{warehouse_code}_{floor_code}_{timestamp}.json`

Example: `layout_WH001_F1_20251111_103000.json`

## Use Cases

### 1. Backup
Export current layout before making major changes

### 2. Clone Layout
Export from one floor and import to another floor in same or different warehouse

### 3. Template
Create standard layouts and reuse across multiple locations

### 4. Migration
Move layouts between development, staging, and production environments

## Important Notes

1. **IDs not preserved**: Database IDs are not included in export, ensuring portability
2. **Code matching**: Import uses location codes (code1+code2) for matching
3. **Overwrite behavior**: Import overwrites walls and fixed areas completely
4. **Zone merging**: Import merges zones based on codes (creates new or updates existing)
5. **Validation**: Canvas size validated to 500-10000px range on import
