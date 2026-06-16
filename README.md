# Algolia Ingestion for Magento 2

A Magento extension that routes Algolia product indexing operations through the [Algolia Ingestion API](https://www.algolia.com/doc/rest-api/ingestion/). 

This enables support for **pre-indexing JavaScript transformations**, low latency Collections and observability through the Algolia platform.


## Requirements

- PHP 8.3+
- Magento 2.4+
- `algolia/algoliasearch-magento-2` ^3.19.0

## Installation

### Via Composer (Recommended)

```bash
composer require algolia/algoliasearch-ingestion-magento-2
bin/magento module:enable Algolia_Ingestion
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

`setup:upgrade` creates the `algoliasearch_ingestion_task` table used to track the source/destination/task pipeline per store and index.

### Manual Installation

1. Download the module files.
2. Place them in `app/code/Algolia/Ingestion/`.
3. Run the commands above starting from `module:enable`.

## Configuration

> **Prerequisite:** Algolia credentials must be configured at
> **Stores > Configuration > Algolia Search > Credentials and Basic Setup**
> before enabling this module. The Ingestion API uses the same Application ID and Admin API Key.

## Limitations

- Requires `algolia/algoliasearch-magento-2` ^3.19.0 - the strategy interface that allows delivery routing was introduced in that release.
- JavaScript transformations must be configured in the Algolia dashboard independently. This module handles delivery to the pipeline; transformation logic lives in Algolia.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
