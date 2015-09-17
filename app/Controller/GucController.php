<?php
App::uses('Scrapper', 'Lib');
App::uses('DatesUtils', 'Lib');

Class GucController extends Controller {

    public $uses=array(
        'Agency',
        'Event',
        'EventMetadatum'
    );

    private $scrapper;
    private $dateBounds;
    private $timeBounds;
    
    public function beforeFilter(){
        date_default_timezone_set('UTC');

        $endpoint = 'http://sismologia.cl/events/listados/%YEAR%/%MONTH%/%YEAR%%MONTH%%DAY%.html';
        
        $endpointTokens = array(
            '%YEAR%',
            '%MONTH%',
            '%DAY%'
        );

        $this->scrapper = new Scrapper($endpoint, $endpointTokens);
    }
    
    public function getFromDateRange($startDate,$endDate){
        $this->dateBounds = array(
            'start'=>Date('Y-m-d', DatesUtils::toTimestamp($startDate)),
            'end'=>Date('Y-m-d', DatesUtils::toTimestamp($endDate))
        );

        $self = $this;

        DatesUtils::rangeLoop($startDate,$endDate,function($day,$month,$year) use ($self){
            $self->doScrapping($self->scrapper->getScrappingUrl(array($year,$month,$day)));
        }); 

    }

    public function getFromtoday($mode='verbose'){
        $currentUTCTimestamp = strtotime(date('Y-m-d H:i:s', time() ));
        $currentUTCDate = date('Y-m-d', $currentUTCTimestamp );

        $this->dateBounds = array(
            'start' => $currentUTCDate,
            'end' => $currentUTCDate
        );

        $this->timeBounds = array(
            'start' => date('Y-m-d H:i:s', $currentUTCTimestamp ),
            'end' => date('Y-m-d H:i:s', $currentUTCTimestamp )
        );

        $this->doScrapping($this->scrapper->getScrappingUrl( array( date('Y',$currentUTCTimestamp),date('m',$currentUTCTimestamp),date('d',$currentUTCTimestamp))) );
    }

    private function doScrapping($endpoint){
        $event = null;
        $earthquake = null;

        Debugger::dump('endpoint: ' . $endpoint . '    _' . $_SERVER['HTTP_USER_AGENT']);
        Debugger::dump('***INICIANDO SCRAPPING****');

        $content = $this->scrapper->getContent($endpoint);
        if ($content){
            $this->scrapper->domLoad($content);
            $tableList = $this->scrapper->findInDom('table tbody tr');
        }else{
          Debugger::dump('***ERROR, NO SE OBTUBIERON DATOS');  
        }
        
        //get each table node
        foreach ($tableList as $key => $table) {
            $earthquakeData=array();
            //get each data item
            foreach ($table->find('td') as $key => $tableItem) {
                $earthquakeData[$key] = $tableItem->text();
            }

            //ignore invalid items
            if ($earthquakeData){
                $dateUTC = $earthquakeData[1];
                $dateTs = DatesUtils::toTimestamp($dateUTC);
                $dateSQL = DatesUtils::toSQLDate($dateUTC);

                $eventData=array(
                    'lat' => $earthquakeData[2],
                    'lon' => $earthquakeData[3],
                    'ts' => $dateSQL,
                    'hash' => md5($dateTs)
                );

                /*  Evitar crear eventos duplicados que muestren erroneamente más de un evento siendo que se trata del mismo
                 *  pero actualizado.
                 *  Esto se hace debido a que el primer informe ante un evento, puede ser preliminar
                 *  y se pueden publicar actualizaciones de datos con cambios en magnitud o ubicación geográfica posteriormente.
                 */

                $eventExists=$this->Event->checkForExists($eventData, $this->dateBounds);

                if ($eventExists['exists']){
                    Debugger::dump('***EVENTO YA EXISTE ****');
                  //echo ('evento ya existe <br>');
                    $event = $eventExists;
                }else{
                    Debugger::dump('***NO SE ENCONTRO EVENTO, CREANDO ****');
                   $this->Event->create();
                   $event = $this->Event->save($eventData);
                }

                if ($event){
                    $metadatum=array(
                        'event_id' => $event['Event']['id'],
                        'agency_id' => 1,
                        'lat' => $eventData['lat'],
                        'lon' => $eventData['lon'],
                        'ts' => $dateSQL,
                        'depth' => $earthquakeData[4],
                        'magnitude' => floatval($earthquakeData[5]),
                        'geo_reference' => $earthquakeData[6]
                    );

                    if (!$eventExists['exists']){
                           Debugger::dump('***EVENTO NO EXISTE, SISMO TAMPOCO ****');
                      
                       $this->EventMetadatum->create();
                       $earthquake=$this->EventMetadatum->save($metadatum);
                    }else{
                        $earthquakeExists = $this->EventMetadatum->checkForExists($metadatum,$this->dateBounds,$eventExists['Event']['id']);
                        if ($earthquakeExists['exists']){
                            Debugger::dump('***EVENTO EXISTE, SISMO TAMBIEN ****');
                        }else{
                            Debugger::dump('***EVENTO EXISTE, NUEVO SISMO NO. CREANDO NUEVO ASOCIADO A EVENTO****');
                            $this->EventMetadatum->create();
                            $earthquake = $this->EventMetadatum->save($metadatum);
                        }

                    }

                }

            }else{
                Debugger::dump('***NO HAY DATOS****');
            }
        }
    }



}
