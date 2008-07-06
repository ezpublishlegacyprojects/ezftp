<?php

class eZFTPInfo
{
    function info()
    {
        return array( 'Name' => 'eZFTP extension',
                      'Version' => '0.0.x',
                      'Copyright' => 'Copyright (C) 2008 Damien Pitard',
                      'License' => 'GNU General Public License v2.0',
                      'Based on the following third-party software' => array( 'Name' => 'nanoFTPd',
                                                                              'License' => 'GNU General Public License v2.0',
                                                                              'Authors' => 'Arjen <arjenjb@wanadoo.nl>, Phanatic <linux@psoftwares.hu>',
                                                                              'For more information' => 'http://nanoftpd.sourceforge.net' )
                    );
    }
}
?>