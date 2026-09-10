# Dreame Robot for IP-Symcon

Experimental IP-Symcon 9.0 module for Dreame robot vacuums connected to the
Dreamehome app. The first release targets the **Dreame X60 Pro Ultra Complete**
and deliberately implements only a small, testable feature set.

## Current feature set

- Dreamehome login through the European endpoint (`de`) used for Germany and
  Switzerland
- Vacuum discovery and automatic X60 selection
- State, battery, charging state, error, cleaning time and cleaned area
- Start/resume, pause, return to dock, stop and locate
- Refresh-token reuse and increasing retry delay after communication failures

Maps, rooms, zones, cleaning modes and station maintenance actions are not part
of version 0.1.

## Installation and first test

1. Add this repository to **Module Control** in IP-Symcon, or copy the complete
   `Dreame-Symcon` directory to a repository used by Module Control.
2. Create a **Dreame Saugwischroboter** instance.
3. Select the Dreamehome region in which the robot is registered.
4. Enter the same email address or phone number and password used by the
   Dreamehome app.
5. Leave the device ID empty for the first test.
6. Click **Anmeldung testen und Geräte suchen**.
7. If more than one robot is listed, copy the desired device ID into the field.
8. Enable **Aktiv** and apply the changes.

Do not post screenshots or debug logs without removing account names, device
IDs, MAC addresses and tokens.

## Reliability notes

The module polls once per minute by default. After errors it increases the wait
time up to 15 minutes instead of continuously retrying. A successful request
restores the configured interval.

The access and refresh tokens are kept as non-visible IP-Symcon instance
attributes. The configured password remains an IP-Symcon property so that the
module can recover if Dreame invalidates the refresh token.

## Disclaimer

The Dreamehome interface is undocumented and can change without notice. This
project is not affiliated with or endorsed by Dreame Technology. See
[`NOTICE.md`](NOTICE.md) for the protocol reference attribution.
