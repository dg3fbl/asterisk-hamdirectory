<?php
// Devolve em JSON: endpoint, display name e se está online (tem IP)

// Cabeçalho JSON
header('Content-Type: application/json; charset=utf-8');

// --- Helper para executar comandos no Asterisk ---
function run_cmd($cmd) {
    $out = array();
    exec("asterisk -rx '" . $cmd . "'", $out);
    return $out;
}

// --- Descobrir se SIP e/ou PJSIP estão ativos ---
$mods = run_cmd("module show like sip");
$uses_sip   = false;
$uses_pjsip = false;

foreach ($mods as $line) {
    if (strpos($line, 'chan_sip.so')   !== false && strpos($line, 'Running') !== false) $uses_sip   = true;
    if (strpos($line, 'chan_pjsip.so') !== false && strpos($line, 'Running') !== false) $uses_pjsip = true;
}

// --- SIP (chan_sip) ---
function get_sip_endpoints() {
    $rows  = array();
    $lines = run_cmd("sip show peers");

    foreach ($lines as $line) {
        $line = rtrim($line);

        // Ignorar cabeçalho, linhas vazias e resumo
        if ($line == '' ||
            strpos($line, 'Name/username') !== false ||
            strpos($line, 'peers') !== false) {
            continue;
        }

        // Exemplo:
        // 1001/1001          192.168.1.10      D   N      5060     OK (9 ms)
        // ramal200/200       (Unspecified)     D   N      0        UNKNOWN
        if (!preg_match('/^\s*(\S+)\s+(\S+)\s+(.+)$/', $line, $m)) {
            continue;
        }

        $peerField = $m[1];      // 1001/1001
        $ip        = $m[2];      // 192.168.1.10 ou (Unspecified)

        // endpoint = parte antes da "/"
        $endpoint = $peerField;
        if (strpos($peerField, '/') !== false) {
            $parts    = explode('/', $peerField, 2);
            $endpoint = $parts[0];
        }

        // Display name: vem de "sip show peer <endpoint>" -> Callerid
        $display = '';
        $detail  = run_cmd("sip show peer " . $endpoint);
        foreach ($detail as $d) {
            if (stripos($d, 'Callerid') !== false) {
                // Callerid     : "Nome" <1001>
                if (preg_match('/Callerid\s*:\s*\"(.*?)\"/', $d, $mm)) {
                    $display = $mm[1];
                }
                break;
            }
        }

        // Online = tem IP (não é (Unspecified) nem 0.0.0.0)
        $online = ($ip !== '(Unspecified)' && $ip !== '0.0.0.0');

        $rows[] = array(
            'endpoint' => $endpoint,
            'display'  => $display,
            'online'   => $online
        );
    }

    return $rows;
}

// --- PJSIP (chan_pjsip) ---
function get_pjsip_endpoints() {

    $rows  = array();
    $lines = run_cmd("pjsip show endpoints");

    $current = null;

    foreach ($lines as $line) {
        $line = trim($line);
        // Novo endpoint: "Endpoint:  1001"
        if (preg_match('/^Endpoint:\s+(\S+)/', $line, $m)) {
            if (!empty($current)) {
                $rows[] = $current;
            }
            $endp = explode("/", $m[1]);

            $current = array(
                'endpoint' => $endp[0],
                'display'  => '',
                'ip'       => '',
                'online'   => false
            );
            continue;
        }

        if (!$current) continue;

        // Contact:  1001/sip:1001@192.168.1.50:5060;ob
        if (preg_match('/^\s*Contact:\s+.*sip:[^@]+@([^; >]+)/', $line, $m)) {
            $hostPort           = $m[1];              // 192.168.1.50:5060
            $current['ip']      = explode(':', $hostPort)[0];
            $current['online']  = ($current['ip'] != '');
            continue;
        }
    }

    if (!empty($current)) {
        $rows[] = $current;
    }

    // Buscar display name via "pjsip show endpoint <endpoint>"
    foreach ($rows as $idx => $ep) {
        $detail = run_cmd("pjsip show endpoint " . $ep['endpoint']);
        foreach ($detail as $d) {
            if (stripos($d, 'callerid') !== false) {
                // callerid : "Nome" <1001>
                if (preg_match('/callerid\s*:\s*\"(.*?)\"/i', $d, $mm)) {
                    $rows[$idx]['display'] = $mm[1];
                }
                break;
            }
        }
    }

    // Converter para o formato apenas com endpoint/display/online
    $simple = array();
    foreach ($rows as $ep) {
        $simple[] = array(
            'endpoint' => $ep['endpoint'],
            'display'  => $ep['display'],
            'online'   => $ep['online']
        );
    }

    return $simple;
}

// --- Juntar resultados ---
$data = array();
if ($uses_sip)   $data = array_merge($data, get_sip_endpoints());
if ($uses_pjsip) $data = array_merge($data, get_pjsip_endpoints());

// --- Output JSON ---
echo json_encode($data, JSON_PRETTY_PRINT);
?>
