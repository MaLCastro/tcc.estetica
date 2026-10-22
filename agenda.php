<?php
// ============================================================
// INICIAR SESSÃO, CONEXÃO E VALIDAR O ACESSO
// ============================================================
session_start();
require_once("conexao.php");

if (
    !isset($_SESSION["usuario_id"]) ||
    ($_SESSION["usuario_tipo"] ?? "") !== "cliente"
) {
    header("Location: login.php");
    exit();
}

$cliente_id = (int)$_SESSION["usuario_id"];
date_default_timezone_set("America/Sao_Paulo");

// ============================================================
// RECEBER E VALIDAR O SERVIÇO E A PROFISSIONAL
// ============================================================
$servico_id = filter_input(INPUT_GET, "servico_id", FILTER_VALIDATE_INT);
$profissional_id = filter_input(INPUT_GET, "profissional_id", FILTER_VALIDATE_INT);

$erro = "";
$mensagem = "";
$servico = null;
$horarios_disponiveis = [];

$data_selecionada = $_GET["data"] ?? date("Y-m-d");
$data_objeto = DateTime::createFromFormat("!Y-m-d", $data_selecionada);

if (
    !$data_objeto ||
    $data_objeto->format("Y-m-d") !== $data_selecionada ||
    $data_selecionada < date("Y-m-d")
) {
    $data_selecionada = date("Y-m-d");
    $erro = "Selecione uma data válida, a partir de hoje.";
}

// ============================================================
// FUNÇÃO PARA CONVERTER DURAÇÃO
// ============================================================
function converterDuracaoMinutos($duracao)
{
    $texto = trim((string)$duracao);
    
    // Caso já seja um número direto (ex: 120, 90, 45)
    if (ctype_digit($texto)) {
        return (int)$texto;
    }
    
    // Formato 1h, 1h30, 1h 30min
    if (preg_match('/^(\d+)\s*h(?:\s*(\d+)\s*(?:min)?)?$/u', mb_strtolower($texto), $partes)) {
        $horas = (int)$partes[1];
        $minutos = isset($partes[2]) ? (int)$partes[2] : 0;
        return ($horas * 60) + $minutos;
    }
    
    // Formato somente minutos com texto (ex: 90min)
    if (preg_match('/^(\d+)\s*min$/u', mb_strtolower($texto), $partes)) {
        return (int)$partes[1];
    }
    
    // Formato HH:MM (ex: 01:30)
    if (preg_match('/^(\d+):(\d+)$/', $texto, $partes)) {
        return ((int)$partes[1] * 60) + (int)$partes[2];
    }
    
    return 0;
}

// ============================================================
// FUNÇÃO PARA FORMATAR DURAÇÃO DE FORMA AMIGÁVEL
// ============================================================
function formatarDuracaoAmigavel($minutos)
{
    $h = floor($minutos / 60);
    $m = $minutos % 60;
    if ($h > 0 && $m > 0) {
        return "{$h}h{$m}";
    }
    if ($h > 0) {
        return "{$h}h";
    }
    return "{$m}min";
}

// ============================================================
// BUSCAR SERVIÇO + VINCULAÇÃO (com valores personalizados)
// ============================================================
try {
    if (!$servico_id || !$profissional_id) {
        throw new Exception("Serviço ou profissional não informado.");
    }

    $consulta = $conexao->prepare("
        SELECT
            s.id,
            s.nome_servico,
            s.preco,
            s.duracao,
            v.preco_customizado,
            v.duracao_customizada,
            u.id AS profissional_id,
            u.nome AS profissional_nome
        FROM servicos s
        INNER JOIN vinculacao v ON v.servico_id = s.id AND v.profissional_id = :pid
        INNER JOIN usuarios u ON u.id = :pid
        WHERE s.id = :sid
          AND s.status = 'ativo'
          AND u.tipo_usuario = 'profissional'
          AND u.status = 'ativo'
        LIMIT 1
    ");
    $consulta->execute([
        ":sid" => $servico_id,
        ":pid" => $profissional_id
    ]);
    $servico = $consulta->fetch(PDO::FETCH_ASSOC);

    if (!$servico) {
        throw new Exception("O serviço não está disponível para esta profissional.");
    }

    // Aplicar valores personalizados se existirem
    $preco_final = $servico["preco_customizado"] ?: $servico["preco"];
    $duracao_texto = $servico["duracao_customizada"] ?: $servico["duracao"];
    $duracao_servico = converterDuracaoMinutos($duracao_texto);

    if ($duracao_servico <= 0) {
        throw new Exception(
            "A duração deste serviço precisa ser conferida no cadastro."
        );
    }

    // ========================================================
    // VERIFICAR LIBERAÇÃO DA DATA (ajustada)
    // ========================================================
    $consulta = $conexao->prepare("
        SELECT COUNT(*)
        FROM liberacao_agenda
        WHERE profissional_id = :pid
          AND :data BETWEEN data_inicio AND data_fim
    ");
    $consulta->execute([
        ":pid" => $profissional_id,
        ":data" => $data_selecionada
    ]);
    $data_liberada = (int)$consulta->fetchColumn() > 0;

    // ========================================================
    // CONSULTAR JORNADA (tabela horarios — sem disponibilidade)
    // ========================================================
    $dia_semana = (int)date("w", strtotime($data_selecionada));

    $consulta = $conexao->prepare("
        SELECT trabalha, horario_inicio, ultimo_horario,
               inicio_almoco, fim_almoco
        FROM horarios
        WHERE profissional_id = :pid
          AND dia_semana = :dia
        LIMIT 1
    ");
    $consulta->execute([
        ":pid" => $profissional_id,
        ":dia" => $dia_semana
    ]);
    $jornada = $consulta->fetch(PDO::FETCH_ASSOC);

    if (!$data_liberada) {
        $mensagem = "Esta data ainda não foi liberada para agendamentos.";
    } elseif (!$jornada || (int)$jornada["trabalha"] !== 1) {
        $mensagem = "A profissional não atende neste dia.";
    } else {
        // ====================================================
        // BLOQUEIOS
        // ====================================================
        $consulta = $conexao->prepare("
            SELECT horario_inicio, horario_fim, motivo
            FROM bloqueios_agenda
            WHERE profissional_id = :pid
              AND data_bloqueio = :data
        ");
        $consulta->execute([
            ":pid" => $profissional_id,
            ":data" => $data_selecionada
        ]);
        $bloqueios_dia = $consulta->fetchAll(PDO::FETCH_ASSOC);

        // ====================================================
        // AGENDAMENTOS EXISTENTES
        // ====================================================
        $consulta = $conexao->prepare("
            SELECT a.horario, a.status, s.duracao,
                   v.duracao_customizada
            FROM agendamentos a
            LEFT JOIN servicos s ON s.id = a.servico_id
            LEFT JOIN vinculacao v 
              ON v.servico_id = a.servico_id 
             AND v.profissional_id = a.profissional_id
            WHERE a.profissional_id = :pid
              AND a.data_agendamento = :data
              AND a.status <> 'cancelado'
        ");
        $consulta->execute([
            ":pid" => $profissional_id,
            ":data" => $data_selecionada
        ]);
        $agendamentos_dia = $consulta->fetchAll(PDO::FETCH_ASSOC);

        // ====================================================
        // CÁLCULO DE HORÁRIOS LIVRES
        // ====================================================
        $inicio_expediente = strtotime($jornada["horario_inicio"]);
        $ultimo_atendimento = strtotime($jornada["ultimo_horario"]);

        $inicio_almoco = !empty($jornada["inicio_almoco"])
            ? strtotime($jornada["inicio_almoco"])
            : null;
        $fim_almoco = !empty($jornada["fim_almoco"])
            ? strtotime($jornada["fim_almoco"])
            : null;

        for (
            $inicio = $inicio_expediente;
            $inicio <= $ultimo_atendimento;
            $inicio += 30 * 60
        ) {
            $fim = $inicio + ($duracao_servico * 60);

            // Ignorar horários passados no mesmo dia
            if ($data_selecionada === date("Y-m-d")) {
                if ($inicio <= time()) continue;
            }

            $hora_inicio = date("H:i:s", $inicio);
            $hora_fim = date("H:i:s", $fim);

            if ($inicio > $ultimo_atendimento) continue;

            // Intervalo de almoço
            if (
                $inicio_almoco !== null &&
                $fim_almoco !== null &&
                $inicio < $fim_almoco &&
                $fim > $inicio_almoco
            ) {
                continue;
            }

            // Bloqueios
            $conflito_bloqueio = false;
            foreach ($bloqueios_dia as $bloqueio) {
                $bi = strtotime($bloqueio["horario_inicio"]);
                $bf = strtotime($bloqueio["horario_fim"]);
                if ($inicio < $bf && $fim > $bi) {
                    $conflito_bloqueio = true;
                    break;
                }
            }
            if ($conflito_bloqueio) continue;

            // Conflito com agendamentos
            $conflito_agendamento = false;
            foreach ($agendamentos_dia as $agendamento) {
                $ie = strtotime($agendamento["horario"]);
                $duracao_ag = $agendamento["duracao_customizada"] 
                    ?: $agendamento["duracao"];
                $de = converterDuracaoMinutos($duracao_ag);
                if ($de <= 0) {
                    $conflito_agendamento = true;
                    break;
                }
                $fe = $ie + ($de * 60);
                if ($inicio < $fe && $fim > $ie) {
                    $conflito_agendamento = true;
                    break;
                }
            }

            if (!$conflito_agendamento) {
                $horarios_disponiveis[] = date("H:i", $inicio);
            }
        }

        if (empty($horarios_disponiveis)) {
            $mensagem = "Não há horários disponíveis nesta data.";
        }
    }
} catch (Exception $erro_agenda) {
    error_log("Erro ao carregar agenda: " . $erro_agenda->getMessage());
    $erro = $erro_agenda->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Escolher Horário | TCC Estética</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="pagina-cliente pagina-agenda-cliente">
    <header class="cabecalho-cliente">
        <div class="logo-cliente">
            <h1>Estética</h1>
        </div>
        <nav class="menu-cliente">
            <a href="painel_cliente.php">Início</a>
            <a href="servicos.php" class="ativo">Serviços</a>
            <a href="meus_agendamentos.php">Meus Agendamentos</a>
            <a href="logout.php" class="menu-sair">Sair</a>
        </nav>
    </header>

    <main class="agenda-container">
        <section class="agenda-card">
            <h2>Escolha seu horário</h2>
            <?php if ($erro): ?>
                <div class="agenda-mensagem agenda-erro">
                    <?= htmlspecialchars($erro, ENT_QUOTES, "UTF-8") ?>
                </div>
                <a href="servicos.php" class="agenda-botao">Voltar aos serviços</a>
            <?php elseif ($servico): ?>
                <div class="agenda-resumo">
                    <strong>Serviço:</strong>
                    <?= htmlspecialchars($servico["nome_servico"], ENT_QUOTES, "UTF-8") ?><br>
                    <strong>Profissional:</strong>
                    <?= htmlspecialchars($servico["profissional_nome"], ENT_QUOTES, "UTF-8") ?><br>
                    <strong>Duração:</strong>
                    <?= htmlspecialchars($duracao_texto, ENT_QUOTES, "UTF-8") ?><br>
                    <strong>Valor:</strong>
                    R$ <?= number_format((float)$preco_final, 2, ",", ".") ?>
                </div>

                <form method="GET" action="agenda.php" class="agenda-formulario">
                    <input type="hidden" name="servico_id" value="<?= (int)$servico_id ?>">
                    <input type="hidden" name="profissional_id" value="<?= (int)$profissional_id ?>">
                    <div>
                        <label for="data">Data desejada</label>
                        <input
                            type="date"
                            id="data"
                            name="data"
                            min="<?= date("Y-m-d") ?>"
                            value="<?= htmlspecialchars($data_selecionada, ENT_QUOTES, "UTF-8") ?>"
                            required
                        >
                    </div>
                    <button type="submit" class="agenda-botao">Consultar horários</button>
                </form>

                <h3>Horários disponíveis</h3>
                <?php if ($mensagem): ?>
                    <div class="agenda-mensagem">
                        <?= htmlspecialchars($mensagem, ENT_QUOTES, "UTF-8") ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($horarios_disponiveis)): ?>
                    <div class="agenda-horarios">
                        <?php foreach ($horarios_disponiveis as $horario): ?>
                            <a 
                                href="confirmar_agendamento.php?servico_id=<?= (int)$servico_id ?>&profissional_id=<?= (int)$profissional_id ?>&data=<?= $data_selecionada ?>&horario=<?= $horario ?>"
                                class="agenda-horario"
                            >
                                <?= htmlspecialchars($horario, ENT_QUOTES, "UTF-8") ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <p class="agenda-mensagem">
                        A disponibilidade será verificada novamente antes de confirmar.
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
