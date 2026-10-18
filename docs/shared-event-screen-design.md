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

## Shared Event List Notes

- The shared event list should be a separate screen linked from the app menu.
- Show all eligible events without pagination.
- Each row should use the shared profile name as its heading and show the event date and a short message snippet.
- Keep expired events in the history until login cleanup removes them 30 days after expiry.
- Label expired rows and do not link them to the QR display page; only unexpired events can reopen a QR.
- The QR icon in the list should be avoided if it is not used for the main workflow.
- Show the latest five unexpired QR pages in the background cache. Cache older QR pages when the user opens them online.

## QR Return Link

- Show a dashboard icon in the footer of the QR display page so the owner can return to the dashboard.
- Do not show event details on the QR display page.

## Guest Flow Notes

- The QR destination is the guest-facing shared event page, not a profile-introduction-only page.
- The guest can see the event content first, then the profile block, then the message form.
- The guest name step remains separate when the flow requires it.

## Documentation Scope

This note is intentionally about the current agreed behavior and screen structure only.
Implementation details can be added later when the feature work starts.
