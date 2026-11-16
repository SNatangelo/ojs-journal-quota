# journalQuota: test di sviluppo (1.0 → 1.7.6)

> Registro storico delle prove di sviluppo. Le versioni 1.0–1.7.6 nelle tabelle indicano revisioni interne precedenti alla release pubblica **1.0.0**, non nuove release pubbliche. Dopo queste prove il plugin è stato installato ed è funzionante su **JSENetwork con MySQL**. La [suite automatizzata](../tests/README.md) è disponibile separatamente; questo registro non è il log di una sua esecuzione.

Tutti i test sono stati fatti sulla copia locale del sito (Docker, OJS 3.5.0-6, MariaDB/InnoDB), mai sul sito online.
Dopo ogni prova le impostazioni delle riviste sono state rimesse come prima e i file di prova cancellati.
Le revisioni esterne sono di Fable 5.2 (audit iniziale e controlli) e di ChatGPT (tre revisioni sulle versioni 1.7.x).

## 1. Misura dello spazio

| Test | Esito | Versione |
|---|---|---|
| Misura iniziale delle 4 riviste (jse, sidm, ECAIR, crecer), parte privata e pubblica | Valori coerenti con il disco | 1.0 |
| Totali del plugin confrontati con `du` | Uguali | 1.4 |
| Caricamento di un file in una submission | +2 MB contati subito | 1.3–1.4 |
| Copia di un file tra fasi (stesso file) | Il totale non cambia | 1.3–1.4 |
| Nuova revisione di un file | +3 MB | 1.3–1.4 |
| Cancellazione dei file | Torna ai valori di partenza; la misura completa coincide | 1.3–1.4 |
| Modifica di un utente collegato (POST) | Rimisura delle parti pubbliche (+3 MB, poi −3 MB) | 1.3 |
| Stessa modifica da utente anonimo | Nessuna rimisura | 1.3 |
| Attività pianificata con misura recente / vecchia di 25 ore | Salta / rifà la misura | 1.3 |
| Campo "ogni quante ore" (scanHours) | Si salva; la pagina mostra l'ultima misura | 1.3 |
| Prestazioni con 20.000 file | Misura completa 0,11–1,2 s, poi 0,25 s; lettura stato 0,5–6,7 ms | 1.3–1.4 |
| Tabelle del database attribuite alle riviste (pubblicazioni senza DOI, tabelle figlie) | Corretto (sidm da 2,91 a 3,19 MB dopo la correzione) | 1.4 |
| Rivista mai misurata | Mostra "non ancora misurata"; misurata al primo caricamento (crecer 1,55 MB) | 1.0 / 1.7.1 |
| Cartella illeggibile durante la misura | I file già contati restano (2.048 byte) | 1.7.1 |
| Cartella leggibile ma non attraversabile (0400) | I file restano contati | 1.7.3 |
| Cartella intera della rivista nascosta (0600) | I file restano contati; cancellati davvero spariscono | 1.7.3 |
| Due misure contemporanee | Il dato più recente non viene sovrascritto | 1.7.1 |

## 2. Avvisi ed email

| Test | Esito | Versione |
|---|---|---|
| T1: server SMTP irraggiungibile | Al controllo successivo l'email si ritenta e parte una sola volta | 1.3 |
| T2–T3: due controlli in parallelo | Una sola notifica e una sola email | 1.3 |
| T4: spazio che oscilla intorno alla soglia | Nessuna email ripetuta; si riarma dopo 24 ore | 1.2–1.3 |
| T5: plugin disattivato | Nessuna notifica | 1.3 |
| Passaggio a "chiudi subito" allo stesso livello | L'email parte (il blocco conta come livello a sé) | 1.1 |
| Isteresi della campanella (2% della quota) | L'avviso non riappare come non letto | 1.7.0 |
| Ritentativo solo per il manager che non ha ricevuto | Ok | 1.7.1 |
| Invio reale dal server SMTP a due indirizzi di prova | 2 email accettate dal server | 1.7.4 |
| Destinatari con invio finto: sidm (4 manager) e crecer (2 manager) | Esattamente i manager della rivista, nessun altro | 1.7.6 |

## 3. Blocco di submission e caricamenti

| Test | Esito | Versione |
|---|---|---|
| Nuova submission su rivista bloccata | "La rivista non accetta submission" | 1.1 |
| L'impostazione OJS disableSubmissions non viene toccata | Resta 0 | 1.1 |
| Caricamento via API da redattore su sidm bloccata | Rifiutato (HTTP 400, messaggio "bloccata") | 1.5 |
| Caricamento dalla griglia della biblioteca | Rifiutato con messaggio | 1.5 |
| Download dei file su rivista bloccata | Permesso (200) | 1.5 |
| Quota a 103 MB: file da 1 MB / 300 KB / altri 300 KB | Rifiutato / accettato / rifiutato ("restano 267,9 KB") | 1.5 |
| File temporanei agganciati (temporaryFileId) in PUT JSON | Rifiutati | 1.6 |
| Allegato minuscolo + file temporaneo da 1 MB nella stessa richiesta | Rifiutato | 1.7.1 |
| Importazione XML con GET | Rifiutata | 1.7.1 |
| Importazione con GET: rimisura di fascicoli e copertine | 0 → 4.096 byte, poi torna a 0 | 1.7.3 |
| Redattore con token API | Rifiutato; amministratore con token passa | 1.7.0 |
| Amministratore del sito | Mai bloccato (caricamento e cancellazione 200) | 1.6 |
| Immagine del profilo | Non bloccata | 1.7.1 |
| Utente anonimo | Riceve l'errore standard di OJS | 1.6 |
| PUT normale senza file | Non rifiutato | 1.6 |
| Caricamento HTTP da redattore su sidm bloccata, dopo la correzione del deadlock | Rifiutato con il messaggio giusto | 1.7.5 |

## 4. Prenotazioni e caricamenti simultanei

| Test | Esito | Versione |
|---|---|---|
| Prenotazione: rifiuto, scadenza, rilascio | Ok | 1.7.0 |
| 3 caricamenti nello stesso istante | Ne passa esattamente 1 | 1.7.0 / 1.7.3 |
| Prenotazione finta valida da 19 MB | Il caricamento viene rifiutato | 1.7.3 |
| Prenotazione scaduta | Viene cancellata | 1.7.3 |
| Importazione con più file | La prenotazione resta fino alla fine; un altro caricamento intanto è rifiutato | 1.7.3 |
| 40 richieste simultanee (5 giri × 8) con la 1.7.4 | 35 deadlock: difetto riprodotto | 1.7.4 |
| Stessa prova dopo la correzione | 0 deadlock, 1 accettato per giro | 1.7.5 |
| 60 richieste simultanee (3 giri × 20), spazio per 6 | 0 deadlock, esattamente 6 per giro | 1.7.5 |

## 5. Pagina dell'amministratore "Spazio delle riviste"

| Test | Esito | Versione |
|---|---|---|
| Salvataggio, limiti personalizzati, ritorno ai predefiniti | Ok | 1.6 |
| Protezione CSRF | Attiva | 1.6 |
| Utente non collegato | Mandato al login | 1.6 |
| "Misura di nuovo": GET o POST senza CSRF / POST con CSRF | Non misura / misura e torna alla pagina | 1.6 |
| Pulsante Impostazioni nella griglia dei plugin | Porta alla pagina Spazio | 1.6 |
| Nome di rivista con codice HTML e `{{ 7*7 }}` (XSS) | Mostrato solo come testo | 1.6 |
| Selettore delle riviste e colori (senza script inline) | Funzionano | 1.5 |
| Pagina complessiva e pagina di una rivista dopo ogni versione | HTTP 200 | 1.7.x |
| Avviso giallo di problema | Compare quando serve, sparisce dopo la pulizia | 1.7.2 |

## 6. Robustezza

| Test | Esito | Versione |
|---|---|---|
| Attività pianificata con cartella illeggibile | Termina senza errori | 1.2 |
| Cartella del plugin non scrivibile | Il controllo funziona lo stesso | 1.7.2 |
| Nessuna cartella scrivibile | Avviso giallo all'amministratore | 1.7.2 |
| Tabelle create da sole all'aggiornamento (schemaVersion 2) | Ok | 1.7.3 |
| Attività pianificata con plugin disattivato | Non fa nulla | 1.7.1 |
| Controllo sintattico di tutti i file PHP dopo ogni modifica | Pulito | tutte |
| Log di OJS dopo le prove | Nessun errore fatale | tutte |

## 7. Scenari non ancora provati al momento del registro

- Email dal sito online ai manager veri (provato solo l'invio reale dalla copia locale).
- Importazione XML completa di un fascicolo reale dentro OJS (provate le singole parti).
- Installazione del pacchetto sul sito online — **successivamente completata** su JSENetwork con MySQL.
