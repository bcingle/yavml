<?php

namespace yavml;

class Router {

    private $app;

    public function __construct($app) {
        $this->app = $app;
    }

    public function getVehicles($user, $request, $response, $args) {
        $vehicleDao = $this->app->get('vehicleDao');
        $vehicles = $vehicleDao->findVehiclesByUserId($user->userId);
        return $response->withJson($vehicles);
    }

    public function addVehicle($user, $request, $response, $args) {
        $vehicleArr = $request->getParsedBody();
        $vehicle = Vehicle::fromArray($vehicleArr);
        $vehicle->userId = $user->userId;
        $vehicleDao = $this->app->get('vehicleDao');
        $vehicle = $vehicleDao->insertVehicle((object)$vehicle);
        return $response->withHeader('Location', `/vehicles/$vehicle->id`)->withJson($vehicle)->withStatus(201);
    }

    public function getVehicle($user, $request, $response, $args) {
        $vehicleId = $args['id'];
        $vehicleDao = $this->app->get('vehicleDao');
        $vehicle = $vehicleDao->findVehicle($user->userId, $vehicleId);
        
        // check to make sure this vehicle is owned by this user
        if (isset($vehicle) && $user->userId === $vehicle->userId) {
            return $response->withJson($vehicle);
        } else {
            return $response->withStatus(404);
        }
        
    }

    public function deleteVehicle($user, $request, $response, $args) {
        $vehicleId = $args['id'];
        $vehicleDao = $this->app->get('vehicleDao');
        if ($vehicleDao->deleteVehicle($vehicleId, $user->userId)) {
            return $response;
        } else {
            return $response->withStatus(404);
        }
    }

    public function getDocumentsForVehicle($user, $request, $response, $args) {
        $vehicleId = $args['id'];
        $documentDao = $this->app->get('documentDao');
        $documents = $documentDao->findAllDocumentsByUserAndVehicleId($user->userId, $vehicleId);
        return $response->withJson($documents);
    }

    public function addDocumentForVehicle($user, $request, $response, $args) {
        $vehicleId = $args['id'];
        $documentDao = $this->app->get('documentDao');
        $documents = $request->getUploadedFiles();
        $body = $request->getParsedBody();
        if (isset($documents['upload'])) {
            $uploaded = $documents['upload'];
            $err = static::getUploadErrorMessage($uploaded->getError());
            if (isset($err)) {
                // upload error
                error_log($err);
                return $response->withStatus(400)->withJson($err);
            }
            $document = new Document();
            $document->title = $body['title'];
            $document->filetype = $body['filetype'];
            $document->filename = $uploaded->getClientFilename();
            $newFilename = uniqid();
            $document->href = $newFilename;
            if (empty($document->title) || empty($document->filename)) {
                error_log('Incomplete request');
                error_log($document->title);
                error_log($document->filename);
                return $response->withStatus(400);
            }
            $uploadDir = $this->app->get('settings')['uploadDir'];
            $destination = "$uploadDir/$newFilename";
            error_log("File destination: $destination");
            $uploaded->moveTo($destination);
            $document = $documentDao->insertDocumentForVehicle($vehicleId, $document);
            return $response->withHeader('Location', "/vehicles/$vehicleId/documents/$document->id")
                    ->withJson($document, 201);
        } else {
            error_log('Document upload not provided');
            return $response->withStatus(400);
        }
        
    }

    public function deleteDocument($user, $request, $response, $args) {
        $vehicleId = $args['vehicleId'];
        $documentId = $args['documentId'];
        $documentDao = $this->app->get('documentDao');
        if ($documentDao->deleteDocumentByUserAndVehicle($user->userId, $vehicleId, $documentId)) {
            return $response->withStatus(204);
        } else {
            return $response->withStatus(404);
        }
    }

    public function fetchDocument($user, $request, $response, $args) {
        $documentId = $args['documentId'];
        $documentDao = $this->app->get('documentDao');
        $document = $documentDao->findVehicleDocumentByUser($user->userId, $documentId);
        if (!isset($document)) {
            return $response->withStatus(404);
        }
        $uploadDir = $this->app->get('settings')['uploadDir'];
        $file = "$uploadDir/{$document->href}";
        $fh = fopen($file, 'rb');
        $stream = new \Slim\HTTP\Stream($fh);
        return $response->withHeader('Content-Type', $document->filetype || 'application/octet-stream')
            ->withHeader('Content-Description', 'File Transfer')
            ->withHeader('Content-Transfer-Encoding', 'binary')
            ->withHeader('Content-Disposition', `attachment; filename="{$document->filename}"`)
            ->withHeader('Expires', '0')
            ->withHeader('Cache-Control', 'must-revalidate, post-check=0, pre-check=0')
            ->withHeader('Pragma', 'public')
            ->withBody($stream); // all stream contents will be sent to the response
    }

    private static function getUploadErrorMessage($error) {
        switch($error) {
            case UPLOAD_ERR_OK:
                return null;
            case UPLOAD_ERR_NO_FILE:
                return 'No file';
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'File too big';
            default:
                return 'Unknown error';
        }
    }

}