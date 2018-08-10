<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\InventoryShippingAdminUi\Ui\DataProvider;

use Magento\Framework\Api\Filter;
use Magento\Framework\Exception\LocalizedException;
use Magento\InventoryShippingAdminUi\Model\SourceSelectionResultAdapterFromRequestItemsFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Item;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface;
use Magento\Framework\App\RequestInterface;
use Magento\InventorySourceSelectionApi\Api\Data\ItemRequestInterfaceFactory;
use Magento\InventorySourceSelectionApi\Api\Data\InventoryRequestInterfaceFactory;

class SourceSelectionDataProvider extends AbstractDataProvider
{
    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var StockByWebsiteIdResolverInterface
     */
    private $stockByWebsiteIdResolver;

    /**
     * @var GetStockItemConfigurationInterface
     */
    private $getStockItemConfiguration;

    /**
     * @var ItemRequestInterfaceFactory
     */
    private $itemRequestFactory;

    /**
     * @var SourceSelectionResultAdapterFromRequestItemsFactory
     */
    private $sourceSelectionResultAdapterFromRequestItemsFactory;

    /**
     * SourceSelectionDataProvider constructor.
     *
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param RequestInterface $request
     * @param OrderRepositoryInterface $orderRepository
     * @param StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver
     * @param GetStockItemConfigurationInterface $getStockItemConfiguration
     * @param ItemRequestInterfaceFactory $itemRequestFactory
     * @param SourceSelectionResultAdapterFromRequestItemsFactory $sourceSelectionResultAdapterFromRequestItemsFactory
     * @param array $meta
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        RequestInterface $request,
        OrderRepositoryInterface $orderRepository,
        StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver,
        GetStockItemConfigurationInterface $getStockItemConfiguration,
        ItemRequestInterfaceFactory $itemRequestFactory,
        SourceSelectionResultAdapterFromRequestItemsFactory $sourceSelectionResultAdapterFromRequestItemsFactory,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->request = $request;
        $this->orderRepository = $orderRepository;
        $this->stockByWebsiteIdResolver = $stockByWebsiteIdResolver;
        $this->getStockItemConfiguration = $getStockItemConfiguration;
        $this->itemRequestFactory = $itemRequestFactory;
        $this->sourceSelectionResultAdapterFromRequestItemsFactory =
            $sourceSelectionResultAdapterFromRequestItemsFactory;
    }

    /**
     * Disable for collection processing | ????
     *
     * @param Filter $filter
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function addFilter(Filter $filter)
    {
        return null;
    }

    /**
     * {@inheritdoc}
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getData(): array
    {
        /** @var \Magento\InventorySourceSelectionApi\Api\Data\ItemRequestInterface[] $requestItems */
        $requestItems = $data = [];
        $orderId = $this->request->getParam('order_id');
        /** @var \Magento\Sales\Model\Order $order */
        $order = $this->orderRepository->get($orderId);
        $websiteId = (int)$order->getStore()->getWebsiteId();
        $stockId = (int)$this->stockByWebsiteIdResolver->execute($websiteId)->getStockId();

        foreach ($order->getAllItems() as $orderItem) {
            if ($orderItem->getIsVirtual()
                || $orderItem->getLockedDoShip()
                || $orderItem->getHasChildren()) {
                continue;
            }

            $orderItemId = $orderItem->getId();
            //TODO: Need to add additional logic for bundle product with flag ship Together
            if ($orderItem->getParentItem() && !$orderItem->isShipSeparately()) {
                $orderItemId = $orderItem->getParentItemId();
            }

            $qty = $orderItem->getSimpleQtyToShip();
            $qty = $this->castQty($orderItem, $qty);
            $sku = $orderItem->getSku();

            $requestItems[] = $this->itemRequestFactory->create([
                'sku' => $sku,
                'qty' => $qty
            ]);

            $data[$orderId]['items'][] = [
                'orderItemId' => $orderItemId,
                'sku' => $sku,
                'product' => $this->getProductName($orderItem),
                'qtyToShip' => $qty,
                'sources' => [],
                'isManageStock' => $this->isManageStock($sku, $stockId)
            ];
        }
        $data[$orderId]['websiteId'] = $websiteId;
        $data[$orderId]['order_id'] = $orderId;

        $sourceAdapter = $this->sourceSelectionResultAdapterFromRequestItemsFactory->create($stockId, $requestItems);
        foreach ($data[$orderId]['items'] as &$item) {
            $item['sources'] = $sourceAdapter->getSkuSources($item['sku']);
        }

        $data[$orderId]['sourceCodes'] = $sourceAdapter->getSources();

        return $data;
    }

    /**
     * @param string $itemSku
     * @param int $stockId
     * @return bool
     * @throws LocalizedException
     */
    private function isManageStock(string $itemSku, int $stockId): bool
    {
        $stockItemConfiguration = $this->getStockItemConfiguration->execute($itemSku, $stockId);

        return $stockItemConfiguration->isManageStock();
    }

    /**
     * Generate display product name
     * @param Item $item
     * @return null|string
     */
    private function getProductName(Item $item)
    {
        //TODO: need to transfer this to html block and render on Ui
        $name = $item->getName();
        /** @var Item $parentItem */
        if ($parentItem = $item->getParentItem()) {
            $name = $parentItem->getName();
            $options = [];
            if ($productOptions = $parentItem->getProductOptions()) {
                if (isset($productOptions['options'])) {
                    $options = array_merge($options, $productOptions['options']);
                }
                if (isset($productOptions['additional_options'])) {
                    $options = array_merge($options, $productOptions['additional_options']);
                }
                if (isset($productOptions['attributes_info'])) {
                    $options = array_merge($options, $productOptions['attributes_info']);
                }
                if (count($options)) {
                    foreach ($options as $option) {
                        $name .= '<dd>' . $option['label'] . ': ' . $option['value'] .'</dd>';
                    }
                } else {
                    $name .= '<dd>' . $item->getName() . '</dd>';
                }
            }
        }

        return $name;
    }

    /**
     * @param Item $item
     * @param string|int|float $qty
     * @return float|int
     */
    private function castQty(Item $item, $qty)
    {
        if ($item->getIsQtyDecimal()) {
            $qty = (double)$qty;
        } else {
            $qty = (int)$qty;
        }

        return $qty > 0 ? $qty : 0;
    }
}
