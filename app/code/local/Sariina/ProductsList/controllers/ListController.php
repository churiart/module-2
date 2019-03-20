<?php 

class Sariina_ProductsList_ListController extends Mage_Core_Controller_Front_Action {
    
    /**
     * Index method that corresponds to URL
     * @return void
     */
    public function indexAction() {
        $this->loadLayout();
        $this->renderLayout();
    }

    public function rssAction() {
    	$this->getResponse()->setHeader(
            'Content-type',
            'application/xml',
            true
        )->sendHeaders();
        // or simply
        // header('Content-type: application/xml');
    	echo $this->getLayout()->createBlock('productslist/products')->getRssContent();
    }

    /**
     * Add ability to export a list of prices
     * @return http response
     */
    public function exportAction() {
        $filename = "لیست قیمت_" . date('Y-m-d') . ".csv";
        header("Content-Type: text/csv");
        header("Content-Disposition: attachment; filename=\"$filename\"");
        $productsBlock = $this->getLayout()->createBlock('productslist/products');
        $products = $productsBlock->getProductsForExport();
        $headers = [
            'نوع کالا',
            'نام کالا',
            'ویژگی اصلی',
            'قیمت نهایی',
            'تخفیف نقدی',
            'رنگ',
            'گارانتی',
            'برند',
            'تغییر قیمت',
            'ترم تسویه',
            'لینک کالا'
        ];
        $fp = fopen('php://output', 'w');
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));
        $headers = array_reverse($headers);
        fputcsv($fp, $headers);
        foreach ($products as $product) {
            $row = [];
            $row[] = $productsBlock->getAttributeSetName($product);
            $row[] = $product->getName();
            $row[] = $product->getData('main_feature');
            $row[] = Mage::getModel('currencymanagerfa/store')->formatPrice($product->getFinalPrice(), false);
            $row[] = strip_tags(Mage::getModel('currencymanagerfa/store')->formatPrice((float) $product->getCashDiscount()));
            $row[] = $product->getAttributeText('color');
            $row[] = $product->getAttributeText('warranty');
            $row[] = $product->getAttributeText('manufacturer');
            $sign = '';
            $priceChange = $productsBlock->getPriceChange($product->getPercent());
            if ($priceChange == 'up') {
                $sign = '+';
            } else if ($priceChange == 'down') {
                $sign = '-';
            }
            $row[] = $sign . round($product->getPercent(), 1) . ' درصد';
            $row[] = Mage::helper('currencymanagerfa')->characterReplace($product->getPaymentTerm());
            $row[] = $product->getProductUrl();
            $row = array_reverse($row);
            fputcsv($fp, $row);
        }
        fclose($fp);
    }
}
