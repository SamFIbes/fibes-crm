# Coverage

The coverage page is the map of where FIBES can connect someone today.

A place is a building, an area, a cabinet (FAT), an OLT, a tower, or a wireless sector. Each one has a service, a status (live, planned, or full), and how many ports are free. A sector stores azimuth, beam width, and range, and the app draws the footprint from those.

## Check

Sales, the call center, operations, the NOC, and support can run a check from a pin or a pasted Google Maps or WhatsApp link. The answer is one of:

- **Covered.** The pin is inside a live building that still has room, or within 150 m of a live cabinet with a free port. Wireless counts as covered only when an existing customer is already within 30 m, which we treat as the same building.
- **Needs a survey.** The pin is inside a live wireless footprint and nobody is already in that building.
- **Near.** The closest live place is within 500 m, but it is not a free port or a lit building.
- **Not covered.** Nothing is in range. If the lead can move to the waitlist, it does.

A cabinet that is marked full, or that has no free ports, never comes back as covered. The two distances are `coverage.covered_m` and `coverage.near_m` in `config/fibes.php` (150 and 500). A saved settings row named `coverage` overrides them.

Qualified now requires a check whose result is covered, near, or needs a survey. A proposal requires a covered check on the deal or its lead, or a feasible survey for that deal or customer.

## Import

CSV (`lat,lng,name,type,capacity`), GeoJSON, and KML/KMZ can be previewed, then saved. A file that cannot be read saves nothing. FiberDesk is a flag, `FIBES_FIBERDESK`. It stays off. There is no live sync. When it is on, a `.fiberdesk` file is read as GeoJSON.

## Map and dispatch

The map is Leaflet with the tile URL in `FIBES_MAP_TILES`. The same map sits on a lead, a deal, and a customer when a pin exists. Dispatch shows the day’s jobs and any technician’s last GPS (saved when they update a job status with a location). The stop order is nearest-neighbour from the depot in `config/fibes.php` (`coverage.depot`, Beirut). The same distance breaks a tie by the smaller id, so the order does not change between runs.

## Survey

Operations schedules a job of type survey. The technician records line of sight, signal, mounting point, cable length, riser, power, materials, and a recommended service. Photos already on the job are stored with the result. A feasible result unlocks Proposal. A repair job cannot store a survey.

## Waitlist

A not-covered lead with a location becomes Waitlisted. When a place is published live, each waitlisted lead inside its area or within the near distance gets one task, and the lead returns to Assigned. Publishing again does not add a second task.

## Permissions

`coverage.check` is for admin, sales, call center, operations, NOC, and support. `coverage.view` is for management, technicians, installers, and the auditor. `coverage.edit` (add, import, publish) is for admin, operations, and the NOC. Everyone else is refused.

## Database

Tests run on SQLite. The check uses a latitude/longitude index, a bounding box, then haversine and a ray-cast in PHP. Wireless footprints are searched out to `coverage.sector_m` (8 km) from the tower pin. On PostgreSQL the migration creates a geometry column and a GiST index when `CREATE EXTENSION postgis` succeeds. `php artisan coverage:benchmark` loads a batch (default 50,000 cabinets), times one check, and deletes the batch. That command is not part of the test suite.
