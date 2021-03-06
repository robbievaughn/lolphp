<?php
/**
 * Created for Lolphp on 1/25/14.
 *
 * @author Robbie Vaughn <robbie@robbievaughn.me>
 */
namespace Lolphp\Repository;

use Lolphp\Entity\Summoner as SummonerEntity;
use Lolphp\Plugin\Cache as CachePlugin;
use Lolphp\Model\Region as Region;

class Summoner extends RepositoryAbstract
{
    const CACHETTL_SUMMONER                 = 600; // 10 minutes.
    const CACHE_SUMMONER                    = 'summoner';
    const CRITERIA_SUMMONERNAME             = 'summonerNames';
    const CRITERIA_SUMMONERID               = 'summonerIds';
    const CRITERIA_REGION                   = 'region';

    const METHODNAME_BYNAME                 = 'by-name';
    const METHODNAME_NAME                   = 'name';

    /**
     * Returns Entities for Summoner based on name and ID search.
     *
     * Records are cached.
     *
     * @param array $criteria
     * @param array $orderBy
     * @param null $limit
     * @param null $offset
     * @return array|\Lolphp\Entity\EntityInterface
     */
    public function findBy(Array $criteria, Array $orderBy = null, $limit = null, $offset = null)
    {

        // Grab the cache.
        $cache                              = $this->entityManager->getConfiguration()->getCache();
        $cachePlugin                        = new CachePlugin($this->entityManager->getConfiguration());
        $outputList                         = [];

        $connection                         = $this->entityManager->getConnection();
        $apiList                            = null;
        $region                             = (new Region())->validateRegion(@($criteria[$this::CRITERIA_REGION]));
        $hashedRegion                       = $cachePlugin->hash($region);

        foreach ($criteria as $type => &$search) {

            // Search must be an array.
            if (!is_array($search)) {
                $search     = [$search];
            }

            /**
             * Cache
             */
            switch ($type) {
                case $this::CRITERIA_SUMMONERNAME:
                    $methodNameSuffix             = 'Name';
                    break;
                case $this::CRITERIA_SUMMONERID:
                    $methodNameSuffix             = 'Id';
                    break;
                default:
                    $methodNameSuffix             = null;
            }

            // If methodName isn't null and the cache exists.
            if ($methodNameSuffix !== null) {
                $methodName                       = 'get' . $methodNameSuffix;

                /**
                 * @var SummonerEntity $cacheData
                 */
                foreach ($search as $key => $value) {
                    $hashedValue                  = $cachePlugin->hash($value);
                    $cacheKey                     = $cachePlugin->getCacheKeyWildcard([$hashedValue, $hashedRegion]);
                    $cacheExists                  = (bool) @($cache->exists($cacheKey));

                    if (!empty($cacheKey) && $cacheExists === true) {
                        $cacheData                = $cache->get($cacheKey);

                        if ($cacheData->$methodName() == $value) {
                            $outputList[]         = $cacheData;
                            unset($search[$key]);
                            continue;
                        }
                    }
                }
            }

            // Perform an API call if search isn't empty.
            if (!empty($search)) {
                switch ($type) {
                    case $this::CRITERIA_SUMMONERNAME:
                        $apiList                = $this->entityManager->call(
                            [
                                 $this::METHODNAME_BYNAME       => implode(',', $search)
                            ],
                            @($criteria[$this::CRITERIA_REGION] ?: $connection::REGION_NORTHAMERICA),
                            $connection::APIMETHOD_SUMMONER
                        );
                        break;
                    case $this::CRITERIA_SUMMONERID:
                        $apiList                = $this->entityManager->call(
                            [
                                $this::METHODNAME_NAME        => $search
                            ],
                            @($criteria[$this::CRITERIA_REGION] ?: $connection::REGION_NORTHAMERICA),
                            [$connection::APIMETHOD_SUMMONER, $connection::APIMETHOD_PARAMTYPE_APPEND]
                        );

                        $summonerNames          = [];

                        foreach ($apiList as $id => $name) {
                            $summonerNames[]    = $name;
                        }

                        return $this->findBy([$this::CRITERIA_SUMMONERNAME  => array_values($summonerNames)]);
                }
            }

            if (!empty($apiList)) {
                break;
            }
        }

        // Check if apiList is empty if cache pulled data.
        if (!empty($apiList)) {
            foreach ($apiList as $summonerName => $summonerData) {
                // Convert the revisionDate from epoch milliseconds to datetime.
                $summonerData->revisionDate         = (new \Lolphp\Plugin\DateTime())->convertMsDateTime(
                    $summonerData->revisionDate
                );

                // Set the region in summonerData.
                $summonerData->region               = $region;

                // Instantiate SummonerEntity.
                $summonerObj                        = new SummonerEntity($summonerData);
                $hashedName                         = $cachePlugin->hash($summonerObj->getName());
                $hashedId                           = $cachePlugin->hash($summonerObj->getId());
                $cacheKey                           =
                    $this::CACHE_SUMMONER .
                    ".${hashedId}.${hashedName}.${hashedRegion}.cache"
                ;
                $cache->save($cacheKey, $summonerObj, $this::CACHETTL_SUMMONER);
                $outputList[]                       = $summonerObj;
            }
        }

        if (!empty($orderBy)) {
            foreach ($orderBy as $orderCol) {
                $outputList = $this->orderBy($outputList, $orderCol, -1);
            }
        }

        return $outputList;
    }

    /**
     * @param $id
     * @return \Lolphp\Entity\EntityInterface|void
     */
    public function find($id)
    {
        /**
         * @var SummonerEntity $summoner
         */
        $cache                  = $this->entityManager->getConfiguration()->getCache();
        $cachePlugin            = new CachePlugin($this->entityManager->getConfiguration());
        $cacheKeyList           = $cachePlugin->getCacheKeys($this::CACHE_SUMMONER);
        $idHash                 = $cachePlugin->hash($id);
        $summoner               = null;

        foreach ($cacheKeyList as $cacheKey) {
            if (stripos($cacheKey, $idHash)) {
                $summoner       = $cache->get($cacheKey);
                break;
            }
        }

        if (!empty($summoner)) {
            $result             = $this->findBy([
                $this::CRITERIA_SUMMONERNAME        => $summoner->getName(),
                $this::CRITERIA_REGION              => $summoner->getRegion()
            ]);
        } else {
            $result     = $this->findBy([$this::CRITERIA_SUMMONERID   => (int) $id]);
        }

        if (is_array($result)) {
            return reset($result);
        } else {
            return $result;
        }
    }

    /**
     * Returns all summoners from cache.
     *
     * @return array|null
     */
    public function findAll()
    {
        /**
         * @var SummonerEntity $summoner
         */
        $cache                  = $this->entityManager->getConfiguration()->getCache();
        $cachePlugin            = new CachePlugin($this->entityManager->getConfiguration());
        $cacheKeyList           = $cachePlugin->getCacheKeys($this::CACHE_SUMMONER);

        $summonerList           = null;
        foreach ($cacheKeyList as $cacheKey) {
            if ($cache->exists($cacheKey)) {
                $summoner       = $cache->get($cacheKey);
                $summonerList[] = $summoner;
            }
        }

        return $summonerList;
    }
}
