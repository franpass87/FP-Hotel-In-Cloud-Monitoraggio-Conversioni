# Verifica binding front-end FP Experience

## Problemi trovati
- Nessun shortcode registrato per `[fp_exp_page]` o `[fp_exp_widget]`, quindi il front-end non reagiva ai salvataggi né caricava i metadati delle esperienze.
- I template mancavano di una normalizzazione condivisa dei metadati (`_fp_highlights`, `_fp_pricing`, `_fp_ticket_types`, ecc.), con il rischio di array/stringhe incoerenti e dati obsoleti in cache del browser.
- Gli asset front-end non venivano caricati né versionati, e mancava la disabilitazione mirata della cache per le pagine che usano i nostri shortcode.

## Fix applicati
- Creato modulo `includes/experience/` caricato in fase `init` (`ModuleLoader`) con:
  - Helper `FP_Exp\Utils\Helpers::get_meta_array()` per restituire sempre array e loggare in debug i meta mancanti.
  - Gestione asset (`FP_Exp\Frontend\Assets`) con enqueue condizionale e versionamento via `filemtime` per `assets/css/front.css` e `assets/js/front.js`.
  - Nuove funzioni di rendering shortcode (`render_page_shortcode`, `render_widget_shortcode`) che:
    - Convalidano `id`, eseguono fallback solo su `fp_experience`, disattivano cache (`Cache-Control: no-store`) e invalidano transients su `save_post_fp_experience`.
    - Leggono i meta `_fp_highlights`, `_fp_inclusions`, `_fp_ticket_types`, `_fp_pricing`, `_fp_meeting_point_*` usando l'helper e degradano correttamente il meeting point se dati mancanti.
    - Espongono dati pricing normalizzati al widget con versione aggiornata per la logica JS.
- Aggiunti nuovi asset:
  - `assets/css/front.css` per la presentazione delle sezioni/page/widget.
  - `assets/js/front.js` per il riepilogo prezzo “live” basato sui meta normalizzati.

## Cosa testare manualmente
1. Creare/modificare un post `fp_experience` e aggiornare highlights/inclusions → visitando una pagina con `[fp_exp_page id="<ID>"]` le modifiche devono apparire subito dopo il refresh.
2. Aggiornare meta di pricing/ticket → ricaricare la pagina con `[fp_exp_widget id="<ID>"]` e verificare che la tabella e il riepilogo “A partire da …” riflettano i nuovi valori.
3. Lasciare vuoto `_fp_meeting_point_id` ma compilare l’alternativo: la sezione meeting point deve mostrare solo il testo disponibile senza errori e senza link Maps se non ci sono coordinate/indirizzo.
4. Abilitare il livello di log “debug” dalle impostazioni e rendere mancante un meta atteso → verificare nel log che venga scritto un messaggio `[FP_Exp]` mascherato.
