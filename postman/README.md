# Algolia Ingestion API - Postman Collection

Full CRUD requests for the Algolia Ingestion (Connectors) API, covering authentications, destinations, sources, tasks, transformations, and observability (runs and events).

## Prerequisites

- An Algolia account with Ingestion (Connectors) enabled
- An Admin API key with Ingestion read permissions
- [Postman](https://www.postman.com/downloads/) desktop client or web app

## Import

1. Open Postman and choose File -> **Import** (top left).
2. Import `collection.json`.
3. Import `environment.example.json` as a separate import.

## Configure the environment

1. In Postman, open the **Environments** tab (UI may vary) and select `Algolia Ingestion API - Example`.
2. Set the following required variables:

| Variable | Description |
|---|---|
| `algoliaAppId` | Your Algolia Application ID |
| `algoliaApiKey` | Admin API key with Ingestion read permissions |
| `baseUrl` | API base URL (`us` by default) - see [Regions](#regions) below |

3. Save and set this environment as active (top-right environment selector - varies based on UI version - may not be obvious!).

## Regions

The `baseUrl` defaults to `https://data.us.algolia.com`. Change the region segment to match your Algolia application:

| Region | Base URL |
|---|---|
| America (US) | `https://data.us.algolia.com` |
| Europe (EU) | `https://data.eu.algolia.com` |

## Collection structure

The collection is organized into six folders, one per resource type:

| Folder | Requests | API version |
|---|---|---|
| Authentications | List, Get, Create, Update (PATCH), Delete | `/1/` |
| Destinations | List, Get, Create, Update (PATCH), Delete | `/1/` |
| Sources | List, Get, Create, Update (PATCH), Delete | `/1/` |
| Tasks | List, Get, Create, Update (PATCH), Replace (PUT), Delete | `/2/` |
| Transformations | List, Get, Create, Update (PUT), Delete | `/1/` |
| Observability | List Runs, Get Run, List Events, Get Event | `/1/` |

## Running list requests

Each list endpoint (`List Authentications`, `List Destinations`, etc.) includes all supported query parameters pre-filled with example values but **disabled by default**. To use them:

1. Open a list request and go to the **Params** tab.
2. Check the checkbox next to any parameter you want to include.
3. Adjust the value as needed and send.

> **Array parameters** (`type`, `authenticationID`, `sourceID`, `status`, etc.) accept multiple values by repeating the key. Add a new row with the same key name for each additional value.

## Running get requests

Each get endpoint (`Get Authentication`, `Get Destination`, etc.) uses a path variable bound to the corresponding environment variable.

1. Run the relevant list request first to find a valid resource ID.
2. Copy the ID and paste it into the environment variable (`authenticationID`, `destinationID`, etc.).
3. Send the get request.

## Running write requests

Each create, update, and delete request includes a minimal JSON body pre-filled with required fields.

- **Create (POST)** - Required fields are populated using environment variables where applicable (e.g. `{{sourceID}}`, `{{authenticationID}}`). Adjust values before sending.
- **Update/PATCH** - Sends only the fields you want to change. Expand the body with additional fields as needed.
- **Replace/PUT** (tasks and transformations only) - Full replacement. Include all fields you want to keep, not just the changed ones.
- **Delete** - No body required. Set the relevant ID environment variable and send.

> **Tasks have two update verbs:** `Update Task` (PATCH) for partial updates and `Replace Task` (PUT) for full replacement. This matches the upstream API which exposes both `updateTask` and `replaceTask` as distinct operations.

> **Transformations use PUT for updates** (not PATCH) because the upstream API replaces the full transformation object.

## Running observability requests

Runs and events let you monitor the execution history of your tasks.

**Typical workflow:**

1. Run `List Runs` to find recent task runs. Use the `taskID` query parameter to filter by a specific task.
2. Copy a `runID` from the response and set it in your Postman environment.
3. Run `Get Run` to inspect a single run, or `List Events` to see the individual steps within that run.
4. To inspect a specific event, copy an `eventID` from the List Events response, set it in the environment, and run `Get Event`.

**Environment variables used by Observability requests:**

| Variable | Description |
|---|---|
| `runID` | ID of a specific task run |
| `eventID` | ID of a specific event within a run |

## API versioning note

Most endpoints use path prefix `/1/`. Tasks endpoints use `/2/tasks` - this is intentional and reflects the upstream API versioning in the Algolia Ingestion API.

## Authentication

Postman's built-in auth type only supports one header but Algolia requires two headers. To address this, the collection uses a pre-request script (set at the collection level) to inject `X-Algolia-Application-Id` and `X-Algolia-API-Key` headers from the active environment into every request automatically. No per-request header configuration is needed.
