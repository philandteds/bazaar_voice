<?php

/**
 * @package BazaarVoice
 * @class   BazaarVoiceFeedBase
 * @author  Serhey Dolgushev <dolgushev.serhey@gmail.com>
 * @date    25 May 2014
 * */
class BazaarVoiceFetchFunctions {

    public function fetchTranslatedObjectDataMapWrapper( $objectID, $sa ) {
        return array( 'result' => self::fetchTranslatedObjectDataMap( $objectID, $sa ) );
    }

    public static function fetchTranslatedObjectDataMap( $objectID, $sa ) {
        $object = eZContentObject::fetch( $objectID );
        if( $object instanceof eZContentObject === false ) {
            return null;
        }

        $ini     = eZINI::getSiteAccessIni( $sa, 'site.ini' );
        $locales = $ini->variable( 'RegionalSettings', 'SiteLanguageList' );
        foreach( $locales as $locale ) {
            $dataMap = self::fetchObjectDataMap( $object->attribute( 'id' ), $object->attribute( 'current_version' ), $locale );
            if( count( $dataMap ) === 0 ) {
                continue;
            }

            return array(
                'data_map' => $dataMap,
                'language' => $locale
            );
        }

        return null;
    }

    public static function fetchObjectDataMap( $objectID, $version, $language ) {
        $dataMap = array();
        $data    = $version->fetchAttributes( $version, $objectID, $language );
        foreach( $data as $item ) {
            $dataMap[$item->contentClassAttributeIdentifier()] = $item;
        }

        return $dataMap;
    }

}
