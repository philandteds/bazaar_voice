<?php

/**
 * @package BazaarVoice
 * @class   BazaarVoiceFeedPostPurchase
 * @author  Serhey Dolgushev <dolgushev.serhey@gmail.com>
 * @date    23 apr 2014
 * */
class BazaarVoiceFeedPostPurchase extends BazaarVoiceFeedBase {

    public function __construct() {
        self::$ini = eZINI::instance( 'bazaar_voice.ini' );

        self::$dom = new DOMDocument( '1.0', 'UTF-8' );
        self::$dom->formatOutput = true;

        self::$feed = self::$dom->createElement( 'Feed' );
        self::$feed->setAttribute( 'xmlns', self::$ini->variable( 'PostPurchaseFeed', 'XMLNS' ) );
        self::$dom->appendChild( self::$feed );

        $this->localFile  = self::$ini->variable( 'PostPurchaseFeed', 'LocalFile' );
        $this->remoteFile = self::$ini->variable( 'PostPurchaseFeed', 'RemoteFile' );
    }

    protected function build() {
        $minOrderID = self::$ini->variable( 'PostPurchaseFeed', 'MinOrderID' );

        $conds  = array(
            'id'           => array( '>=', $minOrderID ),
            'is_temporary' => 0
        );
        $sorts  = array( 'id' => 'asc' );
        $orders = eZOrder::fetchObjectList(
                eZOrder::definition(), null, $conds, $sorts
        );


        $k     = 1;
        $count = count( $orders );
        foreach( $orders as $order ) {
            $item = self::$dom->createElement( 'Interaction' );
            self::$feed->appendChild( $item );

            $attr = self::$dom->createElement( 'TransactionDate', date( 'c', $order->attribute( 'created' ) ) );
            $item->appendChild( $attr );

            $attr = self::$dom->createElement( 'EmailAddress', htmlentities( $order->attribute( 'account_email' ) ) );
            $item->appendChild( $attr );

            $attr = self::$dom->createElement( 'UserName', htmlentities( $order->attribute( 'account_name' ) ) );
            $item->appendChild( $attr );

            $locale     = null;
            $orderItems = eZOrderItem::fetchListByType( $order->attribute( 'id' ), 'siteaccess' );
            if( count( $orderItems ) > 0 ) {
                $sa = $orderItems[0]->attribute( 'description' );

                $ezLocale = eZLocale::instance( eZINI::getSiteAccessIni( $sa, 'site.ini' )->variable( 'RegionalSettings', 'ContentObjectLocale' ) );
                $locale   = $ezLocale->attribute( 'http_locale_code' );
            }

            $attr = self::$dom->createElement( 'Locale', $locale );
            $item->appendChild( $attr );

            $productsContainer = self::$dom->createElement( 'Products' );
            $item->appendChild( $productsContainer );

            $products = $order->attribute( 'product_items' );
            foreach( $products as $product ) {
                $productItem = self::$dom->createElement( 'Product' );
                $productsContainer->appendChild( $productItem );

                $price = self::$dom->createElement( 'Price', (float) $product['total_price_inc_vat'] );
                $productItem->appendChild( $price );

                $itemObject = $product['item_object'];
                if( $itemObject instanceof eZProductCollectionItem ) {
                    $object = $itemObject->attribute( 'contentobject' );
                    if( $object->attribute( 'class_identifier' ) === 'xrow_product' ) {
                        $productID = BazaarVoiceFeedProducts::getProductID( $object );
                        $productItem->appendChild( $productID );
                    }
                }
            }

            if( $k % self::$progressDebugStep === 0 || $k === 1 || $k === $count ) {
                self::debugProgress( $k, $count );
            }
            $k++;
        }
    }

}
