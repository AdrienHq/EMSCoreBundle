<?php

namespace EMS\CoreBundle\Controller;

use Doctrine\ORM\EntityManager;
use Elasticsearch\Client;
use Elasticsearch\Common\Exceptions\ElasticsearchException;
use Elasticsearch\Common\Exceptions\Missing404Exception;
use Elasticsearch\Common\Exceptions\NoNodesAvailableException;
use EMS\CommonBundle\Elasticsearch\Response\Response as CommonResponse;
use EMS\CommonBundle\Helper\EmsFields;
use EMS\CommonBundle\Search\Search as CommonSearch;
use EMS\CommonBundle\Service\ElasticaService;
use EMS\CoreBundle\Entity\ContentType;
use EMS\CoreBundle\Entity\Form\ExportDocuments;
use EMS\CoreBundle\Entity\Form\Search;
use EMS\CoreBundle\Entity\Form\SearchFilter;
use EMS\CoreBundle\Entity\UserInterface;
use EMS\CoreBundle\Form\Field\IconTextType;
use EMS\CoreBundle\Form\Field\SubmitEmsType;
use EMS\CoreBundle\Form\Form\ExportDocumentsType;
use EMS\CoreBundle\Form\Form\SearchFormType;
use EMS\CoreBundle\Repository\ContentTypeRepository;
use EMS\CoreBundle\Repository\EnvironmentRepository;
use EMS\CoreBundle\Service\AggregateOptionService;
use EMS\CoreBundle\Service\ContentTypeService;
use EMS\CoreBundle\Service\JobService;
use EMS\CoreBundle\Service\SearchService;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Throwable;

class ElasticsearchController extends AppController
{
    /**
     * Create an alias for an index.
     *
     * @return RedirectResponse|Response
     * @Route("/elasticsearch/alias/add/{name}", name="elasticsearch.alias.add")
     */
    public function addAliasAction(string $name, Request $request)
    {
        /** @var Client $client */
        $client = $this->getElasticsearch();

        $form = $this->createFormBuilder([])->add('name', IconTextType::class, [
            'icon' => 'fa fa-key',
            'required' => true,
        ])->add('save', SubmitEmsType::class, [
            'label' => 'Add',
            'icon' => 'fa fa-plus',
            'attr' => [
                'class' => 'btn btn-primary pull-right',
            ],
        ])->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $params['body'] = [
                'actions' => [
                    [
                        'add' => [
                            'index' => $name,
                            'alias' => $form->get('name')->getData(),
                        ],
                    ],
                ],
            ];

            $client->indices()->updateAliases($params);
            $this->getLogger()->notice('log.elasticsearch.alias_added', [
                'alias_name' => $form->get('name')->getData(),
                'index_name' => $name,
            ]);

            return $this->redirectToRoute('environment.index');
        }

        return $this->render('@EMSCore/elasticsearch/add-alias.html.twig', [
            'form' => $form->createView(),
            'name' => $name,
        ]);
    }

    /**
     * @param string $_format
     *
     * @return Response
     *
     * @Route("/health_check.{_format}", defaults={"_format"="html"}, name="health-check")
     */
    public function healthCheckAction($_format)
    {
        try {
            $client = $this->getElasticsearch();
            $status = $client->cluster()->health();

            $response = $this->render('@EMSCore/elasticsearch/status.'.$_format.'.twig', [
                'status' => $status,
                'globalStatus' => $status['status'],
            ]);

            $allowOrigin = $this->getParameter('ems_core.health_check_allow_origin');
            if (!empty($allowOrigin)) {
                $response->headers->set('Access-Control-Allow-Origin', $allowOrigin);
            }

            return $response;
        } catch (Exception $e) {
            throw new ServiceUnavailableHttpException('Due to '.$e->getMessage());
        }
    }

    /**
     * @param string $_format
     *
     * @return Response
     *
     * @Route("/status.{_format}", defaults={"_format"="html"}, name="elasticsearch.status")
     */
    public function statusAction($_format)
    {
        try {
            $client = $this->getElasticsearch();
            $status = $client->cluster()->health();
            $certificateInformation = $this->getDataService()->getCertificateInfo();

            $globalStatus = 'green';
            $tika = null;
            try {
                $tika = ($this->getAssetExtractorService()->hello());
            } catch (Exception $e) {
                $globalStatus = 'yellow';
                $tika = [
                    'code' => 500,
                    'content' => $e->getMessage(),
                ];
            }

            if ('html' === $_format && 'green' !== $status['status']) {
                $globalStatus = $status['status'];
                if ('red' === $status['status']) {
                    $this->getLogger()->error('log.elasticsearch.cluster_red', [
                        'color_status' => $status['status'],
                    ]);
                } else {
                    $this->getLogger()->warning('log.elasticsearch.cluster_yellow', [
                        'color_status' => $status['status'],
                    ]);
                }
            }

            return $this->render('@EMSCore/elasticsearch/status.'.$_format.'.twig', [
                'status' => $status,
                'certificate' => $certificateInformation,
                'tika' => $tika,
                'globalStatus' => $globalStatus,
                'info' => $client->info(),
                'specifiedVersion' => $this->getElasticsearchService()->getVersion(),
            ]);
        } catch (NoNodesAvailableException $e) {
            return $this->render('@EMSCore/elasticsearch/no-nodes-available.'.$_format.'.twig', [
                'cluster' => $this->getParameter('ems_core.elasticsearch_cluster'),
            ]);
        }
    }

    /**
     * @Route("/admin/phpinfo", name="emsco_phpinfo")
     */
    public function phpInfoAction(): void
    {
        \phpinfo();
        exit;
    }

    /**
     * @param int $id
     *
     * @return RedirectResponse
     *
     * @Route("/elasticsearch/delete-search/{id}", name="elasticsearch.search.delete")
     */
    public function deleteSearchAction($id)
    {
        $em = $this->getDoctrine()->getManager();
        $repository = $em->getRepository('EMSCoreBundle:Form\Search');

        $search = $repository->find($id);
        if (!$search) {
            $this->createNotFoundException('Preset saved search not found');
        }

        $em->remove($search);
        $em->flush();

        return $this->redirectToRoute('elasticsearch.search');
    }

    /**
     * @return Response
     *
     * @Route("/quick-search", name="ems_quick_search", methods={"GET"})
     */
    public function quickSearchAction(Request $request)
    {
        $query = $request->query->get('q', false);

        $em = $this->getDoctrine()->getManager();
        $repository = $em->getRepository('EMSCoreBundle:Form\Search');

        /** @var Search $search */
        $search = $repository->findOneBy([
            'default' => true,
        ]);
        if ($search) {
            $em->detach($search);
            $search->resetFilters();
            /** @var SearchFilter $filter */
            foreach ($search->getFilters() as &$filter) {
                if (empty($filter->getPattern())) {
                    $filter->setPattern($query);
                }
            }
        } else {
            $search = new Search();
            if (false !== $query) {
                $search->getFilters()[0]->setPattern($query)->setBooleanClause('must');
            }
        }

        return $this->forward('EMSCoreBundle:Elasticsearch:search', [
            'query' => null,
        ], [
            'search_form' => $search->jsonSerialize(),
        ]);
    }

    /**
     * @param int    $id
     * @param string $contentType
     *
     * @return RedirectResponse
     *
     * @Route("/elasticsearch/set-default-search/{id}/{contentType}", defaults={"contentType"=false}, name="ems_search_set_default_search_from", methods={"POST"})
     */
    public function setDefaultSearchAction($id, $contentType)
    {
        $em = $this->getDoctrine()->getManager();
        $repository = $em->getRepository('EMSCoreBundle:Form\Search');

        if ($contentType) {
            $contentType = $this->getContentTypeService()->getByName($contentType);
            $searchs = $repository->findBy([
                'contentType' => $contentType->getId(),
            ]);
            /** @var Search $search */
            foreach ($searchs as $search) {
                $search->setContentType(null);
                $em->persist($search);
            }

            $search = $repository->find($id);
            $search->setContentType($contentType);
            $em->persist($search);
            $em->flush();
            $this->getLogger()->notice('log.elasticsearch.default_search_for_content_type', [
                EmsFields::LOG_CONTENTTYPE_FIELD => $contentType->getName(),
            ]);
        } else {
            $searchs = $repository->findBy([
                'default' => true,
            ]);
            /** @var Search $search */
            foreach ($searchs as $search) {
                $search->setDefault(false);
                $em->persist($search);
            }
            $search = $repository->find($id);
            $search->setDefault(true);
            $em->persist($search);
            $em->flush();
            $this->getLogger()->notice('log.elasticsearch.default_search', [
            ]);
        }

        return $this->redirectToRoute('elasticsearch.search', ['searchId' => $id]);
    }

    /**
     * @param string $name
     *
     * @return RedirectResponse
     *
     * @Route("/elasticsearch/index/delete/{name}", name="elasticsearch.index.delete")
     */
    public function deleteIndexAction($name)
    {
        /** @var Client $client */
        $client = $this->getElasticsearch();
        try {
            $client->indices()->delete([
                'index' => $name,
            ]);

            $this->getLogger()->notice('log.elasticsearch.index_deleted', [
                'index_name' => $name,
            ]);
        } catch (Missing404Exception $e) {
            $this->getLogger()->warning('log.elasticsearch.index_not_found', [
                'index_name' => $name,
            ]);
        }

        return $this->redirectToRoute('environment.index');
    }

    /**
     * @return Response
     *
     * @Route("/search.json", name="elasticsearch.api.search")
     */
    public function searchApiAction(Request $request, LoggerInterface $logger, SearchService $searchService, ElasticaService $elasticaService, ContentTypeService $contentTypeService)
    {
        $pattern = $request->query->get('q', '');
        $page = \intval($request->query->get('page', 1));
        $environments = $request->query->get('environment', null);
        $types = $request->query->get('type', null);
        $searchId = $request->query->get('searchId', null);
        $category = $request->query->get('category', null);
        $assetName = $request->query->get('asset_name', false);
        $circleOnly = $request->query->get('circle', false);
        $pageSize = 30;

        /** @var EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        /** @var ContentTypeRepository $contentTypeRepository */
        $contentTypeRepository = $em->getRepository('EMSCoreBundle:ContentType');

        $allTypes = $contentTypeRepository->findAllAsAssociativeArray();

        $contentTypes = [];
        if (!empty($types)) {
            $contentTypes = \explode(',', $types);
        }

        $search = null;
        if (null !== $searchId) {
            $searchRepository = $em->getRepository('EMSCoreBundle:Form\Search');
            $search = $searchRepository->findOneBy([
                'id' => $searchId,
            ]);
        }

        if (!$search instanceof Search) {
            $search = $searchService->getDefaultSearch($contentTypes);
        }

        if ($assetName) {
            // For search only in contentType with Asset field == $assetName.
            $contentTypes = [];
            foreach ($allTypes as $key => $value) {
                if (!empty($value->getAssetField())) {
                    $contentTypes[] = $key;
                }
            }
        }

        if (\count($contentTypes) > 0) {
            $search->setContentTypes($contentTypes);
        }

        if (!empty($environments) && null === $searchId) {
            $search->setEnvironments(\explode(',', $environments));
        }

        $search->setSearchPattern($pattern, true);
        $commonSearch = $searchService->generateSearch($search);

        if ($circleOnly && !$this->get('security.authorization_checker')->isGranted('ROLE_USER_MANAGEMENT')) {
            /** @var UserInterface $user */
            $user = $this->getUser();
            $circles = $user->getCircles();

            $ouuids = [];
            foreach ($circles as $circle) {
                \preg_match('/(?P<type>\w+):(?P<ouuid>\w+)/', $circle, $matches);
                $ouuids[] = $matches['ouuid'];
            }
            $query = $commonSearch->getQuery();
            $boolQuery = $elasticaService->getBoolQuery();
            if (!$query instanceof $boolQuery) {
                if (null !== $query) {
                    $boolQuery->addMust($query);
                }
                $query = $boolQuery;
            }
            $query->addMust($elasticaService->getTermsQuery('_id', $ouuids));
            $commonSearch = new CommonSearch($commonSearch->getIndices(), $query);
        }

        if (null !== $category && 1 === \count($contentTypes)) {
            $contentType = $contentTypeService->getByName(\array_pop($contentTypes));
            if (false !== $contentType) {
                $categoryField = $contentType->getCategoryField();
                if (null !== $categoryField) {
                    $boolQuery = $elasticaService->getBoolQuery();
                    $query = $commonSearch->getQuery();
                    if (!$query instanceof $boolQuery) {
                        if (null !== $query) {
                            $boolQuery->addMust($query);
                        }
                        $query = $boolQuery;
                    }
                    $query->addMust($elasticaService->getTermsQuery($categoryField, $category));
                    $commonSearch = new CommonSearch($commonSearch->getIndices(), $query);
                }
            }
        }

        $commonSearch->setFrom(($page - 1) * $pageSize);
        $commonSearch->setSize($pageSize);
        $results = $elasticaService->search($commonSearch);

        return $this->render('@EMSCore/elasticsearch/search.json.twig', [
            'results' => $results->getResponse()->getData(),
            'types' => $allTypes,
        ]);
    }

    /**
     * @return RedirectResponse
     * @Route("/search/export/{contentType}", name="emsco_search_export", methods={"POST"})
     */
    public function exportAction(Request $request, JobService $jobService, ContentType $contentType)
    {
        $exportDocuments = new ExportDocuments($contentType, $this->generateUrl('emsco_search_export', ['contentType' => $contentType]), '{}');
        $form = $this->createForm(ExportDocumentsType::class, $exportDocuments);
        $form->handleRequest($request);

        /** @var ExportDocuments */
        $exportDocuments = $form->getData();
        $command = \sprintf(
            "ems:contenttype:export %s %s '%s'%s --environment=%s --baseUrl=%s",
            $contentType->getName(),
            $exportDocuments->getFormat(),
            $exportDocuments->getQuery(),
            $exportDocuments->isWithBusinessKey() ? ' --withBusinessId' : '',
            $exportDocuments->getEnvironment(),
            '//'.$request->getHttpHost()
        );
        $user = $this->getUser();
        if (!$user instanceof UserInterface) {
            throw new \RuntimeException('Unexpected user object');
        }

        $job = $jobService->createCommand($user, $command);

        return $this->redirectToRoute('job.status', [
            'job' => $job->getId(),
        ]);
    }

    /**
     * @return RedirectResponse|Response
     *
     * @throws Throwable
     * @Route("/search", name="ems_search")
     * @Route("/search", name="elasticsearch.search")
     */
    public function searchAction(Request $request, LoggerInterface $logger, AggregateOptionService $aggregateOptionService, ElasticaService $elasticaService, SearchService $searchService)
    {
        try {
            $search = new Search();

            //Save the form (uses POST method)
            if ('POST' == $request->getMethod()) {
//                 $request->query->get('search_form')['name'] = $request->request->get('form')['name'];
                $request->request->set('search_form', $request->query->get('search_form'));

                $form = $this->createForm(SearchFormType::class, $search);

                $form->handleRequest($request);
                /** @var Search $search */
                $search = $form->getData();
                $search->setName($request->request->get('form')['name']);
                $search->setUser($this->getUser()->getUsername());

                /** @var SearchFilter $filter */
                foreach ($search->getFilters() as $filter) {
                    $filter->setSearch($search);
                }

                $em = $this->getDoctrine()->getManager();
                $em->persist($search);
                $em->flush();

                return $this->redirectToRoute('elasticsearch.search', [
                    'searchId' => $search->getId(),
                ]);
            }

            if (null != $request->query->get('page')) {
                $page = $request->query->get('page');
            } else {
                $page = 1;
            }

            //Use search from a saved form
            $searchId = $request->query->get('searchId');
            if (null != $searchId) {
                $em = $this->getDoctrine()->getManager();
                $repository = $em->getRepository('EMSCoreBundle:Form\Search');
                $search = $repository->find($request->query->get('searchId'));
                if (!$search) {
                    $this->createNotFoundException('Preset search not found');
                }
            }

            $form = $this->createForm(SearchFormType::class, $search, [
                'method' => 'GET',
                'action' => $this->generateUrl('ems_search'),
                'savedSearch' => $searchId,
            ]);

            $form->handleRequest($request);

            $openSearchForm = false;
            $searchButton = $form->get('search');
            if ($searchButton instanceof ClickableInterface) {
                $openSearchForm = $searchButton->isClicked();
            }

            //Form treatment after the "Save" button has been pressed (= ask for a name to save the search preset)
            if ($form->isSubmitted() && $form->isValid() && $request->query->get('search_form') && \array_key_exists('save', $request->query->get('search_form'))) {
                $form = $this->createFormBuilder($search)
                    ->add('name', TextType::class)
                    ->add('save_search', SubmitEmsType::class, [
                        'label' => 'Save',
                        'attr' => [
                            'class' => 'btn btn-primary pull-right',
                        ],
                        'icon' => 'fa fa-save',
                    ])
                    ->getForm();

                return $this->render('@EMSCore/elasticsearch/save-search.html.twig', [
                    'form' => $form->createView(),
                ]);
            } elseif ($form->isSubmitted() && $form->isValid() && $request->query->get('search_form') && \array_key_exists('delete', $request->query->get('search_form'))) {
                //Form treatment after the "Delete" button has been pressed (to delete a previous saved search preset)

                $logger->notice('log.elasticsearch.search_deleted', [
                ]);
            }

            /** @var Search $search */
            $search = $form->getData();

            /** @var EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ContentTypeRepository $contentTypeRepository */
            $contentTypeRepository = $em->getRepository('EMSCoreBundle:ContentType');

            $types = $contentTypeRepository->findAllAsAssociativeArray();

            /** @var EnvironmentRepository $environmentRepository */
            $environmentRepository = $em->getRepository('EMSCoreBundle:Environment');

            $environments = $environmentRepository->findAllAsAssociativeArray('alias');

            $esSearch = $searchService->generateSearch($search);
            $esSearch->setFrom(($page - 1) * $this->getParameter('ems_core.paging_size'));
            $esSearch->setSize($this->getParameter('ems_core.paging_size'));

            $esSearch->addTermsAggregation(AggregateOptionService::CONTENT_TYPES_AGGREGATION, $aggregateOptionService->getContentTypeField(), 15);
            $esSearch->addTermsAggregation(AggregateOptionService::INDEXES_AGGREGATION, '_index', 15);
            $esSearch->addAggregations($aggregateOptionService->getAllAggregations());

            try {
                $response = CommonResponse::fromResultSet($elasticaService->search($esSearch));
                if ($response->getTotal() >= 50000) {
                    $logger->warning('log.elasticsearch.paging_limit_exceeded', [
                        'total' => $response->getTotal(),
                        'paging' => '50.000',
                    ]);
                    $lastPage = \ceil(50000 / $this->getParameter('ems_core.paging_size'));
                } else {
                    $lastPage = \ceil($response->getTotal() / $this->getParameter('ems_core.paging_size'));
                }
            } catch (ElasticsearchException $e) {
                $logger->warning('log.error', [
                    EmsFields::LOG_ERROR_MESSAGE_FIELD => $e->getMessage(),
                    EmsFields::LOG_EXCEPTION_FIELD => $e,
                ]);
                $response = null;
                $lastPage = 0;
            }

            if (null !== $response && !$response->isAccurate()) {
                $logger->warning('log.elasticsearch.search_not_accurate', [
                    'total' => $response->getTotal(),
                ]);
            }

            $currentFilters = $request->query;
            $currentFilters->remove('search_form[_token]');

            //Form treatment after the "Export results" button has been pressed (= ask for a "content type" <-> "template" mapping)
            if (null !== $response && $form->isSubmitted() && $form->isValid() && $request->query->get('search_form') && \array_key_exists('exportResults', $request->query->get('search_form'))) {
                $exportForms = [];
                $contentTypes = $this->getAllContentType($response);
                foreach ($contentTypes as $name) {
                    $contentType = $types[$name];

                    $exportForm = $this->createForm(ExportDocumentsType::class, new ExportDocuments(
                        $contentType,
                        $this->generateUrl('emsco_search_export', ['contentType' => $contentType->getId()]),
                        \json_encode($searchService->generateSearchBody($search))
                    ));

                    $exportForms[] = $exportForm->createView();
                }

                return $this->render('@EMSCore/elasticsearch/export-search.html.twig', [
                    'exportForms' => $exportForms,
                ]);
            }

            $mapIndex = [];
            if (null !== $response) {
                $indexes = $response->getAggregation(AggregateOptionService::INDEXES_AGGREGATION);
                if (null !== $indexes) {
                    foreach ($indexes->getBuckets() as $bucket) {
                        $indexName = $bucket->getKey();
                        if (null === $indexName) {
                            continue;
                        }
                        $aliases = $elasticaService->getAliasesFromIndex($indexName);
                        foreach ($aliases as $alias) {
                            if (isset($environments[$alias])) {
                                $mapIndex[$indexName] = $environments[$alias];
                                break;
                            }
                        }
                    }
                }
            }

            return $this->render('@EMSCore/elasticsearch/search.html.twig', [
                'response' => $response ?? null,
                'lastPage' => $lastPage,
                'paginationPath' => 'elasticsearch.search',
                'types' => $types,
                'indexes' => $mapIndex,
                'form' => $form->createView(),
                'page' => $page,
                'searchId' => $searchId,
                'currentFilters' => $request->query,
                'body' => $searchService->generateSearchBody($search),
                'openSearchForm' => $openSearchForm,
                'search' => $search,
                'sortOptions' => $this->getSortOptionService()->getAll(),
                'aggregateOptions' => $aggregateOptionService->getAll(),
            ]);
        } catch (NoNodesAvailableException $e) {
            return $this->redirectToRoute('elasticsearch.status');
        }
    }

    /**
     * @return string[]
     */
    private function getAllContentType(CommonResponse $response): array
    {
        $aggregation = $response->getAggregation(AggregateOptionService::CONTENT_TYPES_AGGREGATION);
        if (null === $aggregation) {
            return [];
        }

        return $aggregation->getKeys();
    }
}
