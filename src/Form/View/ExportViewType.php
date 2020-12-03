<?php

namespace EMS\CoreBundle\Form\View;

use Dompdf\Adapter\CPDF;
use Dompdf\Dompdf;
use EMS\CommonBundle\Service\ElasticaService;
use EMS\CoreBundle\Entity\View;
use EMS\CoreBundle\Form\Field\CodeEditorType;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactory;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Twig\Environment;

class ExportViewType extends ViewType
{
    /** @var ElasticaService */
    private $elasticaService;

    public function __construct(FormFactory $formFactory, Environment $twig, ElasticaService $elasticaService, LoggerInterface $logger)
    {
        parent::__construct($formFactory, $twig, $logger);
        $this->elasticaService = $elasticaService;
    }

    public function getLabel(): string
    {
        return 'Export: perform an elasticsearch query and generate a export with a twig template';
    }

    public function getName(): string
    {
        return 'Export';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);
        $builder
        ->add('body', CodeEditorType::class, [
                'label' => 'The Elasticsearch body query [JSON Twig]',
                'attr' => [
                ],
                'slug' => 'export_query',
        ])
        ->add('size', IntegerType::class, [
                'label' => 'Limit the result to the x first results',
        ])
        ->add('template', CodeEditorType::class, [
                'label' => 'The Twig template used to display each keywords',
                'attr' => [
                ],
                'slug' => 'export_template',
        ])
        ->add('mimetype', TextType::class, [
                'label' => 'The mimetype used in the response',
                'attr' => [
                ],
        ])
        ->add('allow_origin', TextType::class, [
                'label' => 'The Access-Control-Allow-Originm header',
                'attr' => [
                ],
        ])
        ->add('filename', CodeEditorType::class, [
                'label' => 'The Twig template used to generate the export file name',
                'attr' => [
                ],
                'slug' => 'export_filename',
                'min-lines' => 4,
                'max-lines' => 4,
        ])
        ->add('disposition', ChoiceType::class, [
                'label' => 'File diposition',
                'expanded' => true,
                'attr' => [
                ],
                'choices' => [
                        'None' => null,
                        'Attachment' => 'attachment',
                        'Inline' => 'inline',
                ],
        ])
        ->add('export_type', ChoiceType::class, [
                'label' => 'Export type',
                'expanded' => false,
                'attr' => [
                ],
                'choices' => [
                        'Raw (HTML, XML, JSON, ...)' => null,
                        'PDF (dompdf)' => 'dompdf',
                ],
        ])
        ->add('pdf_orientation', ChoiceType::class, [
            'required' => false,
            'choices' => [
                'Portrait' => 'portrait',
                'Landscape' => 'landscape',
            ],
        ])
        ->add('pdf_size', ChoiceType::class, [
            'required' => false,
            'choices' => \array_combine(\array_keys(CPDF::$PAPER_SIZES), \array_keys(CPDF::$PAPER_SIZES)),
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'export_view';
    }

    public function generateResponse(View $view, Request $request): Response
    {
        $parameters = $this->getParameters($view, $this->formFactory, $request);

        if (isset($view->getOptions()['export_type']) || 'dompdf' === $view->getOptions()['export_type']) {
            // instantiate and use the dompdf class
            $dompdf = new Dompdf();
            $dompdf->loadHtml($parameters['render']);

            // (Optional) Setup the paper size and orientation
            $dompdf->setPaper(
                (isset($view->getOptions()['pdf_size']) && $view->getOptions()['pdf_size']) ? $view->getOptions()['pdf_size'] : 'A4',
                (isset($view->getOptions()['pdf_orientation']) && $view->getOptions()['pdf_orientation']) ? $view->getOptions()['pdf_orientation'] : 'portrait'
            );

            // Render the HTML as PDF
            $dompdf->render();

            // Output the generated PDF to Browser
            $dompdf->stream($parameters['filename'] ?? 'document.pdf', [
                'compress' => 1,
                'Attachment' => (isset($view->getOptions()['disposition']) && 'attachment' === $view->getOptions()['disposition']) ? 1 : 0,
            ]);
            exit;
        }

        $response = new Response();

        if (!empty($view->getOptions()['disposition'])) {
            $attachment = ResponseHeaderBag::DISPOSITION_ATTACHMENT;
            if ('inline' == $view->getOptions()['disposition']) {
                $attachment = ResponseHeaderBag::DISPOSITION_INLINE;
            }
            $disposition = $response->headers->makeDisposition($attachment, $parameters['filename']);
            $response->headers->set('Content-Disposition', $disposition);
        }

        $response->headers->set('Content-Type', $parameters['mimetype']);
        if ($parameters['allow_origin']) {
            $response->headers->set('Access-Control-Allow-Origin', $parameters['allow_origin']);
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, Accept, Accept-Language, If-None-Match, If-Modified-Since');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS');
        }

        $response->setContent($parameters['render']);

        return $response;
    }

    public function getParameters(View $view, FormFactoryInterface $formFactory, Request $request): array
    {
        try {
            $renderQuery = $this->twig->createTemplate($view->getOptions()['body'])->render([
                    'view' => $view,
                    'contentType' => $view->getContentType(),
                    'environment' => $view->getContentType()->getEnvironment(),
            ]);
        } catch (\Throwable $e) {
            $renderQuery = '{}';
        }

        $searchQuery = [
                'index' => $view->getContentType()->getEnvironment()->getAlias(),
                'type' => $view->getContentType()->getName(),
                'body' => $renderQuery,
        ];

        if (isset($view->getOptions()['size'])) {
            $searchQuery['size'] = $view->getOptions()['size'];
        }

        $search = $this->elasticaService->convertElasticsearchSearch($searchQuery);
        $resultSet = $this->elasticaService->search($search);

        try {
            $render = $this->twig->createTemplate($view->getOptions()['template'])->render([
                    'view' => $view,
                    'contentType' => $view->getContentType(),
                    'environment' => $view->getContentType()->getEnvironment(),
                    'result' => $resultSet->getResponse()->getData(),
            ]);
        } catch (\Throwable $e) {
            $render = 'Something went wrong with the template of the view '.$view->getName().' for the content type '.$view->getContentType()->getName().' ('.$e->getMessage().')';
        }

        try {
            $filename = $this->twig->createTemplate($view->getOptions()['filename'])->render([
                    'view' => $view,
                    'contentType' => $view->getContentType(),
                    'environment' => $view->getContentType()->getEnvironment(),
                    'result' => $resultSet->getResponse()->getData(),
            ]);
        } catch (\Throwable $e) {
            $filename = 'Something went wrong with the template of the view '.$view->getName().' for the content type '.$view->getContentType()->getName().' ('.$e->getMessage().')';
        }

        return [
                'render' => $render,
                'filename' => $filename,
                'mimetype' => empty($view->getOptions()['mimetype']) ? 'application/bin' : $view->getOptions()['mimetype'],
                'allow_origin' => empty($view->getOptions()['allow_origin']) ? null : $view->getOptions()['allow_origin'],
                'view' => $view,
                'contentType' => $view->getContentType(),
                'environment' => $view->getContentType()->getEnvironment(),
        ];
    }
}
