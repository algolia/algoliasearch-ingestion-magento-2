# Algolia Ingestion API - Postman Collection

Read-only sample requests for the Algolia Ingestion (Connectors) API, covering authentications, destinations, sources, tasks, and transformations.

## Prerequisites

- An Algolia account with Ingestion (Connectors) enabled
- An Admin API key with Ingestion read permissions
- [Postman](https://www.postman.com/downloads/) desktop client or web app

## Import

1. Open Postman and choose File → **Import** (top left).
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

## Running list requests

Each list endpoint (`List Authentications`, `List Destinations`, etc.) includes all supported query parameters pre-filled with example values but **disabled by default**. To use them:

1. Open a list request and go to the **Params** tab.
2. Check the checkbox next to any parameter you want to include.
3. Adjust the value as needed and send.

> **Array parameters** (`type`, `authenticationID`, `sourceID`, etc.) accept multiple values by repeating the key. Add a new row with the same key name for each additional value.

## Running get requests

Each get endpoint (`Get Authentication`, `Get Destination`, etc.) uses a path variable bound to the corresponding environment variable.

1. Run the relevant list request first to find a valid resource ID.
2. Copy the ID and paste it into the environment variable (`authenticationID`, `destinationID`, etc.).
3. Send the get request.

## API versioning note

Most endpoints use path prefix `/1/`. Tasks endpoints use `/2/tasks` - this is intentional and reflects the upstream API versioning in the Algolia Ingestion API.

## Authentication

Postman's built-in auth type only supports one header but Algolia requires two headers. To address this, the collection uses a pre-request script (set at the collection level) to inject `X-Algolia-Application-Id` and `X-Algolia-API-Key` headers from the active environment into every request automatically. No per-request header configuration is needed.
