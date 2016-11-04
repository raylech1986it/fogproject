<?php
/**
 * Replication service for images
 *
 * PHP version 5
 *
 * @category ImageReplicator
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * Replication service for images
 *
 * @category ImageReplicator
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class ImageReplicator extends FOGService
{
    /**
     * Where to get the services sleeptime
     *
     * @var string
     */
    public static $sleeptime = 'IMAGEREPSLEEPTIME';
    /**
     * Initializes the ImageReplicator Class
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        list(
            $dev,
            $log,
            $zzz
        ) = self::getSubObjectIDs(
            'Service',
            array(
                'name' => array(
                    'IMAGEREPLICATORDEVICEOUTPUT',
                    'IMAGEREPLICATORLOGFILENAME',
                    self::$sleeptime
                )
            ),
            'value',
            false,
            'AND',
            'name',
            false,
            ''
        );
        static::$log = sprintf(
            '%s%s',
            (
                self::$logpath ?
                self::$logpath :
                '/opt/fog/log/'
            ),
            (
                $log ?
                $log :
                'fogreplicator.log'
            )
        );
        if (file_exists(static::$log)) {
            unlink(static::$log);
        }
        static::$dev = (
            $dev ?
            $dev :
            '/dev/tty1'
        );
        static::$zzz = (
            $zzz ?
            $zzz :
            600
        );
    }
    /**
     * This is what almost all services have available
     * but is specific to this service
     *
     * @return void
     */
    private function _commonOutput()
    {
        try {
            $StorageNodes = $this->checkIfNodeMaster();
            foreach ((array)$StorageNodes as &$StorageNode) {
                self::out(
                    sprintf(
                        ' * %s',
                        _('I am the group manager')
                    ),
                    static::$dev
                );
                self::wlog(
                    sprintf(
                        '* %s',
                        _('I am the group manager')
                    ),
                    '/opt/fog/log/groupmanager.log'
                );
                $myStorageGroupID = $StorageNode->get('storagegroupID');
                $myStorageNodeID = $StorageNode->get('id');
                $StorageGroup = $StorageNode->getStorageGroup();
                self::outall(
                    sprintf(
                        ' * %s.',
                        _('Starting Image Replication')
                    )
                );
                self::outall(
                    sprintf(
                        ' * %s: %d. %s: %s',
                        _('We are group ID'),
                        $StorageGroup->get('id'),
                        _('We are group name'),
                        $StorageGroup->get('name')
                    )
                );
                self::outall(
                    sprintf(
                        ' * %s: %d. %s: %s',
                        _('We are node ID'),
                        $StorageNode->get('id'),
                        _('We are node name'),
                        $StorageNode->get('name')
                    )
                );
                /**
                 * Get the image ids that are to be replicated.
                 * NOTE: Must be enabled and have Replication enabled.
                 */
                $ImageIDs = self::getSubObjectIDs(
                    'Image',
                    array(
                        'isEnabled'=>1,
                        'toReplicate'=>1
                    )
                );
                /**
                 * Find any images that are no longer valid within
                 * fog, but still existing in the group assoc.
                 */
                $ImageAssocs = self::getSubObjectIDs(
                    'ImageAssociation',
                    array('imageID' => $ImageIDs),
                    'imageID',
                    true
                );
                /**
                 * If any assocs exist from prior, remove
                 */
                if (count($ImageAssocs)) {
                    self::getClass('ImageAssociationManager')
                        ->destroy(array('imageID' => $ImageAssocs));
                }
                unset($ImageAssocs);
                $ImageAssocCount = self::getClass('ImageAssociationManager')
                    ->count(
                        array(
                            'storagegroupID' => $myStorageGroupID,
                            'imageID' => $ImageIDs
                        )
                    );
                $ImageCount = self::getClass('ImageManager')->count();
                if ($ImageAssocCount <= 0
                    || $ImageCount <= 0
                ) {
                    $this->outall(
                        sprintf(
                            ' | %s.',
                            _('There is nothing to replicate')
                        )
                    );
                    $this->outall(
                        sprintf(
                            ' | %s %s.',
                            _('Please physically associate'),
                            _('images to a storage group')
                        )
                    );
                    continue;
                }
                unset($ImageAssocCount, $ImageCount);
                $imageIDs = self::getSubObjectIDs(
                    'ImageAssociation',
                    array(
                        'storagegroupID' => $myStorageGroupID,
                        'imageID' => $ImageIDs
                    ),
                    'imageID'
                );
                $Images = self::getClass('ImageManager')
                    ->find(array('id' => $imageIDs));
                unset($imageIDs);
                foreach ((array)$Images as &$Image) {
                    if (!$Image->isValid()) {
                        continue;
                    }
                    if (!$Image->getPrimaryGroup($myStorageGroupID)) {
                        self::outall(
                            sprintf(
                                ' | %s: %s',
                                _('Not syncing Image'),
                                $Image->get('name')
                            )
                        );
                        self::outall(
                            sprintf(
                                ' | %s.',
                                _('This is not the primary group')
                            )
                        );
                        continue;
                    }
                    $this->replicateItems(
                        $myStorageGroupID,
                        $myStorageNodeID,
                        $Image,
                        true
                    );
                    unset($Image);
                }
                foreach ($Images as &$Image) {
                    $this->replicateItems(
                        $myStorageGroupID,
                        $myStorageNodeID,
                        $Image,
                        false
                    );
                    unset($Image);
                }
                unset($Images);
                unset($StorageNode);
            }
            unset($StorageNodes);
        } catch (Exception $e) {
            self::outall(
                sprintf(
                    ' * %s',
                    _($e->getMessage())
                )
            );
        }
    }
    /**
     * This is runs the service
     *
     * @return void
     */
    public function serviceRun()
    {
        self::out(
            ' ',
            static::$dev
        );
        $str = str_pad('+', 75, '-');
        self::out($str, static::$dev);
        self::out(
            sprintf(
                ' * %s.',
                _('Checking if I am the group manager')
            ),
            static::$dev
        );
        self::wlog(
            sprintf(
                ' * %s.',
                _('Checking if I am the group manager')
            ),
            '/opt/fog/log/groupmanager.log'
        );
        $this->_commonOutput();
        self::out($str, static::$dev);
        parent::serviceRun();
    }
}
