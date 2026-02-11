<?php
class CalendarHelper {
    
    public static function getLinks($titulo, $descricao, $data_inicio, $duracao_min = 60, $local = "") {
        try {
            $dtStart = new DateTime($data_inicio);
        } catch (Exception $e) {
            $dtStart = new DateTime(); // Fallback para agora se a data for inválida
        }
        
        $dtEnd = clone $dtStart;
        $dtEnd->modify("+$duracao_min minutes");

        $startStr = $dtStart->format('Ymd\THis');
        $endStr = $dtEnd->format('Ymd\THis');
        
        // CORREÇÃO AQUI: Proteção contra valores nulos (?? '')
        $tituloEnc = urlencode($titulo ?? '');
        $descEnc = urlencode($descricao ?? '');
        $locEnc = urlencode($local ?? '');

        // Links
        $google = "https://calendar.google.com/calendar/render?action=TEMPLATE&text=$tituloEnc&dates=$startStr/$endStr&details=$descEnc&location=$locEnc";
        $outlook = "https://outlook.live.com/calendar/0/deeplink/compose?subject=$tituloEnc&body=$descEnc&startdt={$dtStart->format('c')}&enddt={$dtEnd->format('c')}&location=$locEnc";
        $yahoo = "https://calendar.yahoo.com/?v=60&view=d&type=20&title=$tituloEnc&st=$startStr&desc=$descEnc&in_loc=$locEnc";

        // ICS Content
        $icsContent = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Cedro RH//PT\r\nBEGIN:VEVENT\r\nUID:" . uniqid() . "@cedro.ind.br\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\nDTSTART:$startStr\r\nDTEND:$endStr\r\nSUMMARY:".($titulo??'')."\r\nDESCRIPTION:".($descricao??'')."\r\nLOCATION:".($local??'')."\r\nEND:VEVENT\r\nEND:VCALENDAR";
        $icsBase64 = "data:text/calendar;charset=utf8;base64," . base64_encode($icsContent);

        return [
            'google'  => $google,
            'outlook' => $outlook,
            'yahoo'   => $yahoo,
            'ics'     => $icsBase64
        ];
    }
}
?>