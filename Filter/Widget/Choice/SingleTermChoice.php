<?php

/*
 * This file is part of the ONGR package.
 *
 * (c) NFQ Technologies UAB <info@nfq.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ONGR\FilterManagerBundle\Filter\Widget\Choice;

use ONGR\ElasticsearchBundle\Result\Aggregation\AggregationValue;
use ONGR\ElasticsearchDSL\Aggregation\Bucketing\FilterAggregation;
use ONGR\ElasticsearchDSL\Aggregation\Bucketing\TermsAggregation;
use ONGR\ElasticsearchDSL\Query\TermLevel\TermQuery;
use ONGR\ElasticsearchDSL\Search;
use ONGR\ElasticsearchBundle\Result\DocumentIterator;
use ONGR\FilterManagerBundle\Filter\FilterState;
use ONGR\FilterManagerBundle\Filter\Helper\SortAwareTrait;
use ONGR\FilterManagerBundle\Filter\Helper\ViewDataFactoryInterface;
use ONGR\FilterManagerBundle\Filter\ViewData\ChoicesAwareViewData;
use ONGR\FilterManagerBundle\Filter\ViewData;
use ONGR\FilterManagerBundle\Filter\Widget\AbstractFilter;
use ONGR\FilterManagerBundle\Search\SearchRequest;

/**
 * This class provides single terms choice.
 */
class SingleTermChoice extends AbstractFilter implements ViewDataFactoryInterface
{
    use SortAwareTrait;

    /**
     * {@inheritdoc}
     */
    public function modifySearch(Search $search, FilterState $state = null, SearchRequest $request = null)
    {
        if ($state && $state->isActive()) {
            $search->addPostFilter(new TermQuery($this->getDocumentField(), $state->getValue()));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function preProcessSearch(Search $search, Search $relatedSearch, FilterState $state = null)
    {
        $name = $state ? $state->getName() : $this->getDocumentField();
        $aggregation = new TermsAggregation($name, $this->getDocumentField());

        if ($this->getSortOrder()) {
            $aggregation->addParameter('order', [
                $this->getSortType() => $this->getSortOrder()
            ]);
        }

        if ($this->getOption('size') > 0) {
            $aggregation->addParameter('size', $this->getOption('size'));
        }

        if ($relatedSearch->getPostFilters()) {
            $filterAggregation = new FilterAggregation($name . '-filter');
            $filterAggregation->setFilter($relatedSearch->getPostFilters());
            $filterAggregation->addAggregation($aggregation);
            $search->addAggregation($filterAggregation);

            if ($this->getOption('show_zero_choices')) {
                $unfilteredAggregation = clone $aggregation;
                $unfilteredAggregation->setName($name . '-unfiltered');
                $search->addAggregation($unfilteredAggregation);
            }
        } else {
            $search->addAggregation($aggregation);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createViewData()
    {
        return new ChoicesAwareViewData();
    }

    /**
     * {@inheritdoc}
     */
    public function getViewData(DocumentIterator $result, ViewData $data)
    {
        /** @var ChoicesAwareViewData $data */

        $unsortedChoices = [];
        $zeroValueChoices = [];

        if ($this->getOption('show_zero_choices') && $agg = $result->getAggregation($data->getName() . '-unfiltered')) {
            foreach ($agg as $bucket) {
                $zeroValueChoices[$bucket['key']] = $bucket['doc_count'];
            }
        }

        foreach ($this->fetchAggregation($result, $data->getName()) as $bucket) {
            $active = $this->isChoiceActive($bucket['key'], $data);
            $choice = new ViewData\ChoiceAwareViewData();
            $choice->setLabel($bucket['key']);
            $choice->setCount($bucket['doc_count']);
            $choice->setActive($active);
            if ($active) {
                $choice->setUrlParameters($this->getUnsetUrlParameters($bucket['key'], $data));
            } else {
                $choice->setUrlParameters($this->getOptionUrlParameters($bucket['key'], $data));
            }
            $unsortedChoices[$bucket['key']] = $choice;

            if (!empty($zeroValueChoices)) {
                unset($zeroValueChoices[$bucket['key']]);
            }
        }

        foreach ($zeroValueChoices as $choiceLabel => $value) {
            $choice = new ViewData\ChoiceAwareViewData();
            $choice->setLabel($choiceLabel);
            $choice->setCount(0);
            $choice->setActive(false);
            $choice->setUrlParameters($data->getResetUrlParameters());
            $unsortedChoices[$choiceLabel] = $choice;
        }

        $unsortedChoices = $this->addPriorityChoices($unsortedChoices, $data);

        foreach ($unsortedChoices as $choice) {
            $data->addChoice($choice);
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function isRelated()
    {
        return true;
    }

    /**
     * Adds prioritized choices.
     *
     * @param array                $unsortedChoices
     * @param ChoicesAwareViewData $data
     *
     * @return array
     */
    protected function addPriorityChoices(array $unsortedChoices, ChoicesAwareViewData $data)
    {
        foreach ($this->getSortPriority() as $name) {
            if (array_key_exists($name, $unsortedChoices)) {
                $data->addChoice($unsortedChoices[$name]);
                unset($unsortedChoices[$name]);
            }
        }

        return $unsortedChoices;
    }

    /**
     * Fetches buckets from search results.
     *
     * @param DocumentIterator $result Search results.
     * @param string           $name   Filter name.
     *
     * @return AggregationValue.
     */
    protected function fetchAggregation(DocumentIterator $result, $name)
    {
        $aggregation = $result->getAggregation($name);
        if (isset($aggregation)) {
            return $aggregation;
        }

        $aggregation = $result->getAggregation(sprintf('%s-filter', $name));
        return $aggregation->find($name);
    }

    /**
     * @param string   $key
     * @param ViewData $data
     *
     * @return array
     */
    protected function getOptionUrlParameters($key, ViewData $data)
    {
        $parameters = $data->getResetUrlParameters();
        $parameters[$this->getRequestField()] = $key;

        return $parameters;
    }

    /**
     * Returns url with selected term disabled.
     *
     * @param string   $key
     * @param ViewData $data
     *
     * @return array
     */
    protected function getUnsetUrlParameters($key, ViewData $data)
    {
        return $data->getResetUrlParameters();
    }

    /**
     * Returns whether choice with the specified key is active.
     *
     * @param string   $key
     * @param ViewData $data
     *
     * @return bool
     */
    protected function isChoiceActive($key, ViewData $data)
    {
        return $data->getState()->isActive() && $data->getState()->getValue() == $key;
    }
}
