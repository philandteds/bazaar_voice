<?php

/**
 * @package BazaarVoice
 * @author  Serhey Dolgushev <dolgushev.serhey@gmail.com>
 * @date    25 May 2014
 * */
$FunctionList = array(
    'fetch_object' => array(
        'name'           => 'fetch_object',
        'call_method'    => array(
            'class'  => 'BazaarVoiceFetchFunctions',
            'method' => 'fetchTranslatedObjectDataMapWrapper'
        ),
        'parameter_type' => 'standard',
        'parameters'     => array(
            array(
                'name'     => 'object_id',
                'type'     => 'int',
                'required' => true,
                'default'  => null
            ),
            array(
                'name'     => 'sa',
                'type'     => 'string',
                'required' => true,
                'default'  => null
            )
        )
    )
);
