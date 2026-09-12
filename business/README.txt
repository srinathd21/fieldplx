FIELDPLX FULL DYNAMIC THEME PACKAGE

1. Copy the folder to:
   C:\xampp\htdocs\fieldplx\
   or your WAMP www folder.

2. Import:
   database/fieldplx_full.sql

3. Check:
   includes/db.php
   Default localhost values are:
   host = localhost
   user = root
   password = blank
   database = fieldplx

4. Open:
   http://localhost/fieldplx/demo-login.php

5. Pages:
   invoice-view.php
   theme-settings.php

6. Dynamic files:
   includes/header.php
   includes/nav.php
   includes/sidebar.php
   includes/footer.php
   includes/theme-loader.php

7. Theme engine:
   assets/css/theme.css.php
   assets/css/app.css

8. Theme save controller:
   theme-settings-save.php

9. Theme settings are loaded from theme_settings table.

10. Sidebar Theme Settings entry is loaded dynamically from sidebar_menus
    and role_sidebar_access.

IMPORTANT:
This ZIP includes small demo users/roles/sidebar tables only so the package
can be tested alone. If your main project already has these tables, use your
existing tables and only import/create theme_settings plus the Theme Settings
menu row if needed.


V2 ADDITIONS
------------
1. Three gradient categories:
   - Gradient 1: Primary Gradient
   - Gradient 2: Sidebar Active Gradient
   - Gradient 3: Accent Gradient
   Each has Start, Middle, End and Angle.

2. Working typography:
   - Enable/disable dynamic typography
   - Font family dropdown
   - Body, H1, H2, H3 and small font sizes
   - Normal/Medium/Semi Bold/Bold weights
   - Line height
   - Letter spacing

3. Existing database:
   Import database/upgrade_v2_typography_gradients.sql

4. Fresh database:
   Import database/fieldplx_full.sql


V3 FOOTER SAMPLE DATA
---------------------
Footer now includes:
- Current year automatically
- FieldPlx Services
- Business Management System
- Privacy Policy
- Terms
- Support
- Version 1.0.0

Edit:
includes/footer.php
to replace the sample company/footer content.


V4 REFERENCE-STYLE THEME SETTINGS
---------------------------------
Theme Settings page has been redesigned to match the supplied reference style:
- Top summary cards
- Grouped setting sections
- Color picker + HEX input field
- Sticky live preview
- Reset Preview
- Save Theme
- Typography section
- 3 gradient categories
- Responsive desktop/tablet/mobile layout


V5 CORRECTION
-------------
Removed the three separate gradient categories.

Theme Settings now has ONE gradient only:
- Start Color
- Middle Color
- End Color
- Gradient Angle

This matches the requested 3-color gradient behavior.


V6 LIVE PREVIEW + SAVE VERIFICATION
-----------------------------------
Fixed and verified:
- Live color preview
- Live single 3-color gradient preview
- Gradient Start / Middle / End / Angle
- Font family live preview
- Google Fonts loaded for Poppins, Roboto, Open Sans, Montserrat, Lato, Nunito, Raleway and Inter
- Typography enable/disable live preview
- Body/H1/H2/H3/small font sizes
- Font weights, line height and letter spacing
- Primary / brand / button color controls
- Save Theme uses prepared statements with safe dynamic binding
- HEX colors validated on server before saving
- Gradient angle validated
- Existing theme row updates instead of creating duplicates
- New company/branch theme row inserts when needed
- Dynamic CSS reloads saved values from MySQL

Fresh install:
  Import database/fieldplx_full.sql

Existing install:
  Import database/upgrade_existing_to_v6.sql


V7 COMPONENT GRADIENTS
----------------------
Sidebar, Topbar, and Primary Button now have independent gradient settings.

Each component supports:
- Gradient Enabled / Disabled
- Linear
- Radial
- Conic
- Start Color
- Middle Color
- End Color
- Angle / Start Angle
- Position for radial/conic gradients

When disabled, the component falls back to its normal solid theme color.

Existing DB:
  database/upgrade_existing_to_v7_component_gradients.sql

Fresh DB:
  database/fieldplx_full.sql
