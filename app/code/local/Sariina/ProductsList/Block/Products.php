<?php 

class Sariina_ProductsList_Block_Products extends Mage_Core_Block_Template
{
    /**
     * An earlier call to constructor
     * in order to set collection available for other methods
     */
    public function __construct()
    {
        parent::__construct();

        $priceChangeHistoryTableName = Mage::getResourceModel('pricechange/history')->getMainTable();

        // Load a category
        $category = $this->_getCategory();

        $products = $category->getProductCollection();
        // Retrieving all product atributes
        $products->addAttributeToSelect('*');
        // Dummy column for working with product's price in raw SQL below
        $products->addExpressionAttributeToSelect('current_price', '{{price}}', 'price');
        // In-stock products only
        $products->addAttributeToFilter('status', [
            'eq' => Mage_Catalog_Model_Product_Status::STATUS_ENABLED
        ]);
        $products->addFinalPrice();
        // Separate products within each manufacturer
        $products->addAttributeToSort('manufacturer', 'ASC');

        $products->getSelect()
        ->columns([
            // Calculate a percentage change 
            'percent' => new Zend_Db_Expr("COALESCE(
                    (SELECT 100 * (current_price - b.price) / current_price 
                        FROM {$priceChangeHistoryTableName} b 
                        WHERE product_id = e.entity_id AND DATE(date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                        ORDER BY date DESC LIMIT 1
                    ),
                    0
                )")
        ]);

        $products->joinField(
                'is_in_stock',
                'cataloginventory/stock_item',
                'is_in_stock',
                'product_id = entity_id',
                '{{table}}.stock_id = 1',
                'left'
        )->addAttributeToFilter('is_in_stock', array('eq' => 1));

        // For later use in pager
        $this->setCollection($products);
    }

    /**
     * Retrieving filtered products
     * @return Varian_Data_Collection
     */
    public function getProducts() {
        return $this->getCollection();
    }

    /**
     * Generate RSS link with `category` number
     * @return string
     */
    public function getRssLink()
    {
        return Mage::getUrl('products/list/rss', array(
            '_query' => array(
                'category' => $this->_getCategoryId()
            )
        ));
    }

    /**
     * Generate CSV file with `category` number
     * @return string
     */
    public function getCsvLink()
    {
        return Mage::getUrl('products/list/export', array(
            '_query' => array(
                'category' => $this->_getCategoryId()
            )
        ));
    }

    /**
     * Generates RSS nodes
     * @return string
     */
    public function getRssContent()
    {
        $products = $this->getProducts();
        $rssObj = Mage::getModel('rss/rss');
        $category = $this->_getCategory();

        $data = array(
            'title' => $category->getName(),
            'description' => $category->getName(),
            'link' => Mage::getUrl('products/category', array(
                '_query' => array(
                    'category' => $this->_getCategoryId()
                )
            )),
            'charset' => 'UTF-8',
        );

        $rssObj->_addHeader($data);

        $args = array('rssObj' => $rssObj);

        foreach ($products as $_product) {
            $args['product'] = $_product;
            $this->addNewItemXmlCallback($args);
        }

        return $rssObj->createRssXml();
    }

    /**
     * Gets attribute set name which is needed in template file
     * @param  Mage_Catalog_Model_Product $product
     * @return string
     */
    public function getAttributeSetName($product)
    {
        $attributeSetModel = Mage::getModel("eav/entity_attribute_set");
        $attributeSetModel->load($product->getAttributeSetId());
        $attributeSetName  = $attributeSetModel->getAttributeSetName();

        return $attributeSetName;
    }

    /**
     * Recognizing CSS classname
     * @param  int $percent
     * @return string
     */
    public function getPriceChange($percent)
    {
        if ($percent > 0) return 'up';
        if ($percent < 0) return 'down';
        return '';
    }

    protected function _getCategory()
    {
        $category = Mage::getModel('catalog/category');
        // Load a specific category if `category` was set in URL
        if ($categoryId = $this->_getCategoryId()) {
            $category->load($categoryId);
        }
        else {
            // We might need to change default category
            $category->load(2);
        }

        return $category;
    }

    protected function _getCategoryId()
    {
        return $this->getRequest()->getParam('category');
    }

    /**
     * Preparing data and adding to RSS object
     * @param  array $args
     * @return void
     */
    public function addNewItemXmlCallback($args)
    {
        $product = $args['product'];
        $product->setAllowedInRss(true);
        $product->setAllowedPriceInRss(true);
        if (!$product->getAllowedInRss()) {
            return;
        }

        $description = "<table>".
        "<tr>
            <td>". $this->getAttributeSetName($product)."</td>
            <td>". $product->getName()."</td>
            <td>". Mage::getModel('currencymanagerfa/store')->formatPrice($product->getFinalPrice(), false)."</td>
            <td>". Mage::getModel('currencymanagerfa/store')->formatPrice((float) $product->getCashDiscount())."</td>
            <td>". $product->getAttributeText('color')."</td>
            <td>". $product->getAttributeText('warranty')."</td>
            <td>". $product->getAttributeText('manufacturer')."</td>
            <td>". $product->getData('main_feature')."</td>
            <td>". round($product->getPercent(), 1)."%<i class='price-arrow ". $this->getPriceChange($product->getPercent()) ."'></i></td>
            <td>". Mage::helper('currencymanagerfa')->characterReplace($product->getPaymentTerm()) ." روز</td>
            <td><a href='". $product->getProductUrl() ."'>مشاهده</a></td>
        </tr>";

        $description .= '</table>';
        $rssObj = $args['rssObj'];
        $data = array(
            'title' => $product->getName(),
            'link' => $product->getProductUrl(),
            'description' => $description,
        );

        $rssObj->_addEntry($data);
    }


    /**
     * Checks if we need to print a row containing manufacturer name
     * @param  Mage_Catalog_Model_Product  $product
     * @param  string  $manufacturer
     * @return boolean
     */
    public function isManufacturerRow($product, &$manufacturer) {
        if ($manufacturer != '') {
            if ($manufacturer != $product->getAttributeText('manufacturer')) {
                $manufacturer = $product->getAttributeText('manufacturer');
                return true;
            }
        } else {
            $manufacturer = $product->getAttributeText('manufacturer');
            return true;
        }
        return false;
    }

    /**
     * Applying some defaults to current page request
     * @return Sariina_ProductsList_Block_Products
     */
    protected function _prepareLayout() {
        parent::_prepareLayout();
        $pager = $this->getLayout()->createBlock('page/html_pager', 'custom.pager');
        // This sets drop down values
        $pager->setAvailableLimit([
            20 => 20,
            50 => 50,
            75 => 75,
            100 => 100
        ]);
        $pager->setCollection($this->getCollection());
        $this->setChild('pager', $pager);
        $this->getCollection()->load();
        return $this;
    }

    public function getToolbarHtml() {
        return $this->getChildHtml('pager');
    }

    public function getProductsForExport() {
        $products = $this->getProducts();
        $products->getSelect()->reset(Zend_Db_Select::LIMIT_COUNT);
        $products->getSelect()->reset(Zend_Db_Select::LIMIT_OFFSET);
        $products->clear();
        $products->setPageSize(false);
        return $products->load();
    }
}
