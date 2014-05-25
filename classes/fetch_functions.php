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
        $versions = self::getObjectVersions( $objectID );

        $ini     = eZINI::getSiteAccessIni( $sa, 'site.ini' );
        $locales = $ini->variable( 'RegionalSettings', 'SiteLanguageList' );
        foreach( $locales as $locale ) {
            if( isset( $versions[$locale] ) ) {
                return array(
                    'data_map' => self::fetchObjectDataMap( $objectID, $versions[$locale] ),
                    'language' => $locale
                );
            }
        }

        return null;
    }

    protected static function getObjectVersions( $objectID ) {
        $conditions = array( 'contentobject_id' => $objectID );
        $sort       = array( 'version' => 'desc' );

        $return   = array();
        $versions = eZPersistentObject::fetchObjectList(
                eZContentObjectVersion::definition(), null, $conditions, $sort
        );

        foreach( $versions as $version ) {
            $langCode = $version->initialLanguageCode();
            if( isset( $return[$langCode] ) ) {
                continue;
            }

            $return[$langCode] = $version;
        }

        return $return;
    }

    public static function fetchObjectDataMap( $objectID, eZContentObjectVersion $version ) {
        $dataMap = array();
        $data    = $version->fetchAttributes(
            $version->attribute( 'version' ), $objectID, self::getVersionLanguage( $version )
        );
        foreach( $data as $item ) {
            $dataMap[$item->contentClassAttributeIdentifier()] = $item;
        }

        return $dataMap;
    }

    protected static function getVersionLanguage( eZContentObjectVersion $version ) {
        return $version->CurrentLanguage ? $version->CurrentLanguage : $version->attribute( 'initial_language' )->attribute( 'locale' );
    }

}
