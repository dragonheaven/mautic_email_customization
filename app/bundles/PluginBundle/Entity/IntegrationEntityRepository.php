<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\PluginBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * IntegrationRepository.
 */
class IntegrationEntityRepository extends CommonRepository
{
    /**
     * @param      $integration
     * @param      $integrationEntity
     * @param      $internalEntity
     * @param null $internalEntityId
     * @param null $startDate
     * @param null $endDate
     * @param bool $push
     * @param int  $start
     * @param int  $limit
     *
     * @return array
     */
    public function getIntegrationsEntityId($integration, $integrationEntity, $internalEntity, $internalEntityId = null, $startDate = null, $endDate = null, $push = false, $start = 0, $limit = 0, $integrationEntityIds = null)
    {
        $q = $this->_em->getConnection()->createQueryBuilder()
            ->select('DISTINCT(i.integration_entity_id), i.id, i.internal_entity_id, i.integration_entity')
            ->from(MAUTIC_TABLE_PREFIX.'integration_entity', 'i');

        $q->where('i.integration = :integration')
            ->andWhere('i.internal_entity = :internalEntity')
            ->setParameter('integration', $integration)
            ->setParameter('internalEntity', $internalEntity);

        if ($integrationEntity) {
            $q->andWhere('i.integration_entity = :integrationEntity')
                ->setParameter('integrationEntity', $integrationEntity);
        }

        if ($push) {
            $q->join('i', MAUTIC_TABLE_PREFIX.'leads', 'l', 'l.id = i.internal_entity_id and l.last_active >= :startDate')
                ->setParameter('startDate', $startDate);
        }

        if ($internalEntityId) {
            $q->andWhere('i.internal_entity_id = :internalEntityId')
                ->setParameter('internalEntityId', $internalEntityId);
        }

        if ($startDate and !$push) {
            $q->andWhere('i.last_sync_date >= :startDate')
                ->setParameter('startDate', $startDate);
        }
        if ($endDate and !$push) {
            $q->andWhere('i.last_sync_date <= :endDate')
                ->setParameter('endDate', $endDate);
        }

        if ($start) {
            $q->setFirstResult((int) $start);
        }
        if ($limit) {
            $q->setMaxResults((int) $limit);
        }
        if ($integrationEntityIds) {
            $q->andWhere('i.integration_entity_id in ('.$integrationEntityIds.')');
        }

        $results = $q->execute()->fetchAll();

        return $results;
    }

    /**
     * @param $integration
     * @param $internalEntity
     * @param $leadFields
     * @param $limit
     * @param $excludeIntegrationIds
     *
     * @return array
     */
    public function findLeadsToUpdate($integration, $internalEntity, $leadFields, $limit = 25, $integrationEntity = ['Contact', 'Lead'], $excludeIntegrationIds = [])
    {
        $q = $this->_em->getConnection()->createQueryBuilder()
            ->from(MAUTIC_TABLE_PREFIX.'integration_entity', 'i')
            ->join('i', MAUTIC_TABLE_PREFIX.'leads', 'l', 'l.id = i.internal_entity_id');

        if (false === $limit) {
            $q->select('count(*) as total');

            if ($integrationEntity) {
                $q->addSelect('i.integration_entity');
            }
        } else {
            $q->select('i.integration_entity_id, i.integration_entity, i.id, i.internal_entity_id,'.$leadFields);
        }

        $q->where('i.integration = :integration');

        if ($integrationEntity) {
            if (!is_array($integrationEntity)) {
                $integrationEntity = [$integrationEntity];
            }
            $sub = $q->expr()->orX();
            foreach ($integrationEntity as $key => $entity) {
                $sub->add($q->expr()->eq('i.integration_entity', ':entity'.$key));
                $q->setParameter(':entity'.$key, $entity);
            }
            $q->andWhere($sub);
        }

        $q->andWhere('i.internal_entity = :internalEntity')
            ->setParameter('integration', $integration)
            ->setParameter('internalEntity', $internalEntity);

        if (!empty($excludeIntegrationIds)) {
            $q->andWhere(
                $q->expr()->notIn(
                    'i.integration_entity_id',
                    array_map(
                        function ($x) {
                            return "'".$x."'";
                        },
                        $excludeIntegrationIds
                    )
                )
            );
        }

        $q->andWhere($q->expr()->isNotNull('i.integration_entity_id'))
            ->andWhere(
                '((i.last_sync_date is not null and l.date_modified > i.last_sync_date) or (i.last_sync_date is null and l.date_modified > i.date_added)) and l.email is not null'
            );

        // Group by email to prevent duplicates from affecting this
        if (false === $limit and $integrationEntity) {
            $q->groupBy('i.integration_entity');
        }

        if ($limit) {
            $q->setMaxResults($limit);
        }

        $results = $q->execute()->fetchAll();
        $leads   = [];

        if ($integrationEntity) {
            foreach ($integrationEntity as $entity) {
                $leads[$entity] = (false === $limit) ? 0 : [];
            }
        }

        foreach ($results as $result) {
            if ($integrationEntity) {
                if (false === $limit) {
                    $leads[$result['integration_entity']] = (int) $result['total'];
                } else {
                    $leads[$result['integration_entity']][$result['internal_entity_id']] = $result;
                }
            } else {
                $leads[$result['internal_entity_id']] = $result['internal_entity_id'];
            }
        }

        return $leads;
    }

    /**
     * @param $integration
     * @param $leadFields
     *
     * @return array
     */
    public function findLeadsToCreate($integration, $leadFields, $limit = 25)
    {
        $q = $this->_em->getConnection()->createQueryBuilder()
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l');

        if (false === $limit) {
            $q->select('count(*) as total');
        } else {
            $q->select('l.id as internal_entity_id,'.$leadFields);
        }

        $q->where('l.date_identified is not null')
            ->andWhere('l.email is not null')
            ->andWhere('not exists (select null from '.MAUTIC_TABLE_PREFIX.'integration_entity i where i.integration = :integration and i.internal_entity LIKE "lead%" and i.internal_entity_id = l.id )')
            ->setParameter('integration', $integration);

        if ($limit) {
            $q->setMaxResults($limit);
        }

        $results = $q->execute()->fetchAll();
        if (false === $limit) {
            return (int) $results[0]['total'];
        }

        $leads = [];
        foreach ($results as $result) {
            $leads[$result['internal_entity_id']] = $result;
        }

        return $leads;
    }

    /**
     * @param array $integrationIds
     * @param       $integration
     * @param       $internalEntityType
     */
    public function markAsDeleted(array $integrationIds, $integration, $internalEntityType)
    {
        $q = $this->_em->getConnection()->createQueryBuilder();
        $q->update(MAUTIC_TABLE_PREFIX.'integration_entity')
            ->set('internal_entity', ':entity')
            ->where(
                $q->expr()->andX(
                    $q->expr()->eq('integration', ':integration'),
                    $q->expr()->in('integration_entity_id', array_map([$q->expr(), 'literal'], $integrationIds))
                )
            )
            ->setParameter('integration', $integration)
            ->setParameter('entity', $internalEntityType.'-deleted')
            ->execute();
    }

    /**
     * @param $integration
     * @param $internalEntity
     * @param $leadId
     *
     * @return array
     */
    public function findLeadsToDelete($internalEntity, $leadId)
    {
        $q = $this->_em->getConnection()->createQueryBuilder()
            ->delete(MAUTIC_TABLE_PREFIX.'integration_entity')
            ->from(MAUTIC_TABLE_PREFIX.'integration_entity');

        $q->where('internal_entity_id = :leadId')
            ->andWhere($q->expr()->like('internal_entity', ':internalEntity'))
            ->setParameter('leadId', $leadId)
            ->setParameter('internalEntity', $internalEntity)
        ->execute();
    }
}
