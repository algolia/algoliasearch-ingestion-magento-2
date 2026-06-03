<?php

namespace Algolia\Ingestion\Console\Command\Ingestion\Renderer;

use Algolia\AlgoliaSearch\Service\StoreNameFetcher;
use Algolia\Ingestion\Model\Cleanup\CleanupPlan;
use Algolia\Ingestion\Model\Cleanup\CleanupResult;
use Algolia\Ingestion\Model\Cleanup\ObjectPlan;
use Algolia\Ingestion\Model\Cleanup\RowPlan;
use Algolia\Ingestion\Model\Cleanup\RowResult;
use Magento\Framework\Exception\NoSuchEntityException;
use Symfony\Component\Console\Output\OutputInterface;

class CleanupReportRenderer
{
    public function __construct(protected StoreNameFetcher $storeNameFetcher) {}

    public function renderPreview(CleanupPlan $plan, OutputInterface $output): void
    {
        $output->writeln(
            '<comment>NOTE: This will delete resources in Algolia for '
            . $this->renderTargetLabel($plan)
            . '.</comment>'
        );
        $output->writeln('Checks performed at ' . $plan->checkedAt->format('H:i:s'));
        $output->writeln('');

        if ($plan->isEmpty()) {
            $output->writeln('<info>No ingestion task records found for the selected scope.</info>');
            return;
        }

        $this->renderDeleteSection($plan, $output);
        $this->renderPreserveSection($plan, $output);

        $output->writeln(sprintf(
            'Summary: %d row(s) targeted; %d object(s) to delete; %d object(s) to preserve.',
            count($plan->rows),
            $plan->totalDeleteCount(),
            $plan->totalPreserveCount()
        ));
    }

    public function renderResult(CleanupResult $result, OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('RESULTS');
        $output->writeln('-------');

        foreach ($result->rows as $rowResult) {
            $output->writeln($this->formatResultLine($rowResult));
        }

        $output->writeln('');
        $output->writeln(sprintf(
            'Summary: %d row(s) succeeded, %d row(s) failed.',
            $result->successCount(),
            $result->failureCount()
        ));
    }

    protected function renderDeleteSection(CleanupPlan $plan, OutputInterface $output): void
    {
        $rowsWithDeletes = array_filter($plan->rows, fn(RowPlan $r) => !empty($r->deletes()));
        if (empty($rowsWithDeletes)) {
            return;
        }

        $output->writeln('WILL DELETE');
        $output->writeln('-----------');
        foreach ($rowsWithDeletes as $row) {
            $output->writeln($this->rowHeader($row));
            foreach ($row->deletes() as $object) {
                $output->writeln(sprintf(
                    '  %s %s',
                    $this->padObjectType($this->objectTypeForPlan($row, $object)),
                    $object->id
                ));
            }
            $output->writeln('');
        }
    }

    protected function renderPreserveSection(CleanupPlan $plan, OutputInterface $output): void
    {
        $hasPreserves = false;
        foreach ($plan->rows as $row) {
            if (!empty($row->preserves()) || !empty($row->preservedTransformationIds)) {
                $hasPreserves = true;
                break;
            }
        }
        if (!$hasPreserves) {
            return;
        }

        $output->writeln('WILL PRESERVE');
        $output->writeln('-------------');
        foreach ($plan->rows as $row) {
            if (empty($row->preserves()) && empty($row->preservedTransformationIds)) {
                continue;
            }
            $output->writeln($this->rowHeader($row));
            foreach ($row->preserves() as $type => $object) {
                $idLabel = $object->id ?? '(none)';
                $output->writeln(sprintf('  %s %s  (%s)', $this->padObjectType($type), $idLabel, $object->reason));
            }
            foreach ($row->preservedTransformationIds as $transformationId) {
                $output->writeln(sprintf(
                    '  %s %s  (survives destination delete; manage in Algolia dashboard)',
                    $this->padObjectType('transformation'),
                    $transformationId
                ));
            }
            $output->writeln('');
        }
    }

    protected function rowHeader(RowPlan $row): string
    {
        return sprintf(
            '%s / %s  (origin: %s)',
            $this->storeLabel($row->storeId),
            $row->indexName,
            $row->originLabel
        );
    }

    protected function storeLabel(int $storeId): string
    {
        try {
            return $this->storeNameFetcher->getStoreName($storeId);
        } catch (NoSuchEntityException) {
            return "store $storeId";
        }
    }

    protected function padObjectType(string $type): string
    {
        return str_pad($type, 14);
    }

    /**
     * @return string Object type key from RowPlan::OBJECT_TYPES that matches the given plan
     *         in $row->objects. ObjectPlan itself doesn't carry its type label.
     */
    protected function objectTypeForPlan(RowPlan $row, ObjectPlan $plan): string
    {
        foreach ($row->objects as $type => $candidate) {
            if ($candidate === $plan) {
                return $type;
            }
        }
        return '?';
    }

    protected function renderTargetLabel(CleanupPlan $plan): string
    {
        if (empty($plan->storeIds)) {
            return 'all stores';
        }
        $names = [];
        foreach ($plan->storeIds as $id) {
            $names[] = $this->storeLabel($id);
        }
        return 'store(s): ' . implode(', ', $names);
    }

    protected function formatResultLine(RowResult $row): string
    {
        $label = sprintf('%s / %s', $this->storeLabel($row->plan->storeId), $row->plan->indexName);
        if ($row->isSuccess()) {
            return sprintf(
                '%s  <info>OK</info> (%d deleted, %d preserved)',
                $label,
                $row->deletedCount,
                $row->preservedCount
            );
        }
        return sprintf(
            '%s  <error>FAILED on %s delete: %s</error>; local row retained, re-run to retry',
            $label,
            $row->failedOnObject ?? 'unknown',
            $row->failureMessage ?? 'unknown error'
        );
    }
}
