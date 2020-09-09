<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\SalesChannel\CrossSelling;

use OpenApi\Annotations as OA;
use Shopware\Core\Content\Product\Aggregate\ProductCrossSelling\ProductCrossSellingCollection;
use Shopware\Core\Content\Product\Aggregate\ProductCrossSelling\ProductCrossSellingDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductCrossSelling\ProductCrossSellingEntity;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\Events\ProductCrossSellingIdsCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductCrossSellingsLoadedEvent;
use Shopware\Core\Content\Product\Events\ProductCrossSellingStreamCriteriaEvent;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingLoader;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Content\Product\SalesChannel\ProductCloseoutFilter;
use Shopware\Core\Content\ProductStream\Service\ProductStreamBuilderInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepositoryInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @RouteScope(scopes={"store-api"})
 */
class ProductCrossSellingRoute extends AbstractProductCrossSellingRoute
{
    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var EntityRepositoryInterface
     */
    private $crossSellingRepository;

    /**
     * @var ProductStreamBuilderInterface
     */
    private $productStreamBuilder;

    /**
     * @var SalesChannelRepositoryInterface
     */
    private $productRepository;

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    /**
     * @var ProductListingLoader
     */
    private $listingLoader;

    public function __construct(
        EntityRepositoryInterface $crossSellingRepository,
        EventDispatcherInterface $eventDispatcher,
        ProductStreamBuilderInterface $productStreamBuilder,
        SalesChannelRepositoryInterface $productRepository,
        SystemConfigService $systemConfigService,
        ProductListingLoader $listingLoader
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->crossSellingRepository = $crossSellingRepository;
        $this->productStreamBuilder = $productStreamBuilder;
        $this->productRepository = $productRepository;
        $this->systemConfigService = $systemConfigService;
        $this->listingLoader = $listingLoader;
    }

    public function getDecorated(): AbstractProductCrossSellingRoute
    {
        throw new DecorationPatternException(self::class);
    }

    /**
     * @OA\Post(
     *      path="/product/{productId}/cross-selling",
     *      description="This route is used to load the cross sellings for a product. A product has several cross selling definitions in which several products are linked. The route returns the cross sellings together with the linked products",
     *      operationId="readProductCrossSellings",
     *      tags={"Store API","Language"},
     *      @OA\Response(
     *          response="200",
     *          description="Found cross sellings",
     *          @OA\JsonContent(ref="#/definitions/CrossSellingElementCollection")
     *     )
     * )
     * @Route("/store-api/v{version}/product/{productId}/cross-selling", name="store-api.product.cross-selling", methods={"POST"})
     */
    public function load(string $productId, SalesChannelContext $context): ProductCrossSellingRouteResponse
    {
        $crossSellings = $this->loadCrossSellings($productId, $context);

        $elements = new CrossSellingElementCollection();

        foreach ($crossSellings as $crossSelling) {
            if ($this->useProductStream($crossSelling)) {
                $element = $this->loadByStream($crossSelling, $context);
            } else {
                $element = $this->loadByIds($crossSelling, $context);
            }

            if ($element && $element->getTotal() > 0) {
                $elements->add($element);
            }
        }

        $this->eventDispatcher->dispatch(new ProductCrossSellingsLoadedEvent($elements, $context));

        return new ProductCrossSellingRouteResponse($elements);
    }

    private function loadCrossSellings(string $productId, SalesChannelContext $context): ProductCrossSellingCollection
    {
        $criteria = new Criteria();
        $criteria
            ->addAssociation('assignedProducts')
            ->addFilter(new EqualsFilter('product.id', $productId))
            ->addFilter(new EqualsFilter('active', 1))
            ->addSorting(new FieldSorting('position', FieldSorting::ASCENDING));

        /** @var ProductCrossSellingCollection $crossSellings */
        $crossSellings = $this->crossSellingRepository
            ->search($criteria, $context->getContext())
            ->getEntities();

        return $crossSellings;
    }

    private function loadByStream(ProductCrossSellingEntity $crossSelling, SalesChannelContext $context): CrossSellingElement
    {
        $filters = $this->productStreamBuilder->buildFilters(
            $crossSelling->getProductStreamId(),
            $context->getContext()
        );

        $criteria = new Criteria();
        $criteria->addFilter(...$filters)
            ->setLimit($crossSelling->getLimit())
            ->addSorting($crossSelling->getSorting());

        $criteria = $this->handleAvailableStock($criteria, $context);

        $this->eventDispatcher->dispatch(
            new ProductCrossSellingStreamCriteriaEvent($crossSelling, $criteria, $context)
        );

        $searchResult = $this->listingLoader->load($criteria, $context);

        /** @var ProductCollection $products */
        $products = $searchResult->getEntities();

        $element = new CrossSellingElement();
        $element->setCrossSelling($crossSelling);
        $element->setProducts($products);

        $element->setTotal($products->count());

        return $element;
    }

    private function loadByIds(ProductCrossSellingEntity $crossSelling, SalesChannelContext $context): ?CrossSellingElement
    {
        if (!$crossSelling->getAssignedProducts()) {
            return null;
        }

        $crossSelling->getAssignedProducts()->sortByPosition();

        $ids = array_values($crossSelling->getAssignedProducts()->getProductIds());

        $filter = new ProductAvailableFilter(
            $context->getSalesChannel()->getId(),
            ProductVisibilityDefinition::VISIBILITY_LINK
        );

        if (!count($ids)) {
            return null;
        }

        $criteria = new Criteria($ids);
        $criteria->addFilter($filter);

        $criteria = $this->handleAvailableStock($criteria, $context);

        $this->eventDispatcher->dispatch(
            new ProductCrossSellingIdsCriteriaEvent($crossSelling, $criteria, $context)
        );

        $result = $this->productRepository
            ->search($criteria, $context);

        /** @var ProductCollection $products */
        $products = $result->getEntities();

        $products->sortByIdArray($ids);

        $element = new CrossSellingElement();
        $element->setCrossSelling($crossSelling);
        $element->setProducts($products);
        $element->setTotal($crossSelling->getAssignedProducts()->count());

        return $element;
    }

    private function handleAvailableStock(Criteria $criteria, SalesChannelContext $context): Criteria
    {
        $salesChannelId = $context->getSalesChannel()->getId();
        $hide = $this->systemConfigService->get('core.listing.hideCloseoutProductsWhenOutOfStock', $salesChannelId);

        if (!$hide) {
            return $criteria;
        }

        $criteria->addFilter(new ProductCloseoutFilter());

        return $criteria;
    }

    private function useProductStream(ProductCrossSellingEntity $crossSelling): bool
    {
        return $crossSelling->getType() === ProductCrossSellingDefinition::TYPE_PRODUCT_STREAM
            && $crossSelling->getProductStreamId() !== null;
    }
}