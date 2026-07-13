# Shared Event Screen Design

This note captures the current screen structure and the discussion points we agreed on for the QR-driven shared event flow.

## Current Screens

- Dashboard
- Account / profile settings
- Profile detail
- Profile edit
- Profile QR display
- Shared event page for guests

## Shared Event Page Layout

The shared event page reached from the QR code should be organized into three blocks.

- Event
  - Event message
  - Event photos
- Profile
  - Optional profile image
  - Headline
  - Bio / introduction
  - SNS links
- Message
  - Guest message form

## Profile Image Notes

- The profile image is optional.
- The profile image must be registered by image upload from the device or by taking a photo with the camera.
- The profile edit screen must not ask the user to paste a profile image URL.
- It can represent different things depending on the use case.
  - Shop logo for a venue
  - Selfie or today photo for a traveler
  - Circle or group icon for a club
- If present, it should also be usable as a small icon in the QR preview.
- The QR must remain readable even when the image is present.

## Event Photo Input Notes

- Event photos are optional and can be attached when creating a share event.
- The photo input must allow choosing existing photos from the device photo library.
- Attached photos must be scaled to fit within the guest screen without cropping or horizontal overflow.
- The photo input may also allow taking a new photo with the camera, but it must not force the camera-only flow.
- On mobile browsers, avoid the `capture` attribute for the normal event photo input because it can bypass the photo picker and immediately open the camera.

## Dashboard List Notes

- The logged-in dashboard should show a compact list of share events.
- Each row should show the event date, the shared profile name, and a short message snippet.
- Expired events should not be listed.
- Each row should link to the guest-facing QR page for that event.
- The QR icon in the list should be avoided if it is not used for the main workflow.

## Guest Flow Notes

- The QR destination is the guest-facing shared event page, not a profile-introduction-only page.
- The guest can see the event content first, then the profile block, then the message form.
- The guest name step remains separate when the flow requires it.

## Documentation Scope

This note is intentionally about the current agreed behavior and screen structure only.
Implementation details can be added later when the feature work starts.
