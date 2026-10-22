<?php
// ============================================================
// INICIAR SESSÃO, CONEXÃO E VALIDAR ACESSO
// ============================================================
session_start();
require_once("conexao.php");

if (!isset($_SESSION["usuario_id"]) || ($_SESSION["usuario_tipo"] ?? "") !== "cliente") {
    header("Location: login.php");
    exit();
}

$servico_id = filter_input(INPUT_GET, "servico_id", FILTER_VALIDATE_INT);
$profissional_id = filter_input(INPUT_GET, "profissional_id", FILTER_VALIDATE_INT);
$data_base = $_GET["data"] ?? date("Y-m-d");

$erro = "";
$servico = null;
$profissional = null;

date_default_timezone_set("America/Sao_Paulo");
$data_hoje = date("Y-m-d");

// ============================================================
// FUNÇÕES DE CONVERSÃO E FORMATAÇÃO
// ============================================================
function converterDuracaoMinutos($duracao)
{
    $texto = trim((string)$duracao);
    if (ctype_digit($texto)) {
        return (int)$texto;
    }
    if (preg_match('/^(\d+)\s*h(?:\s*(\d+)\s*(?:min)?)?$/u', mb_strtolower($texto), $partes)) {
        return ((int)$partes[1] * 60) + (isset($partes[2]) ? (int)$partes[2] : 0);
    }
    if (preg_match('/^(\d+)\s*min$/u', mb_strtolower($texto), $partes)) {
        return (int)$partes[1];
    }
    if (preg_match('/^(\d+):(\d+)$/', $texto, $partes)) {
        return ((int)$partes[1] * 60) + (int)$partes[2];
    }
    return 0;
}

function formatarDuracaoAmigavel($minutos)
{
    $h = floor($minutos / 60);
    $m = $minutos % 60;
    if ($h > 0 && $m > 0) return "{$h}h{$m}";
    if ($h > 0) return "{$h}h";
    return "{$m}min";
}

// ============================================================
// VALIDAR PARÂMETROS E CARREGAR DADOS
// ============================================================
if (!$servico_id || !$profissional_id) {
    $erro = "Serviço ou profissional não informado.";
} else {
    try {
        $consulta = $conexao->prepare("
            SELECT
                s.id,
                s.nome_servico,
                s.duracao,
                s.preco,
                v.duracao_customizada,
                v.preco_customizado,
                u.nome AS profissional_nome
            FROM servicos s
            INNER JOIN vinculacao v ON v.servico_id = s.id AND v.profissional_id = :pid
            INNER JOIN usuarios u ON u.id = :pid
            WHERE s.id = :sid
              AND s.status = 'ativo'
              AND u.status = 'ativo'
            LIMIT 1
        ");
        $consulta->execute([":sid" => $servico_id, ":pid" => $profissional_id]);
        $servico = $consulta->fetch(PDO::FETCH_ASSOC);

        if (!$servico) {
            $erro = "Serviço indisponível para esta profissional.";
        } else {
            $duracao_texto = $servico["duracao_customizada"] ?: $servico["duracao"];
            $duracao_servico = converterDuracaoMinutos($duracao_texto);
            $duracao_exibicao = formatarDuracaoAmigavel($duracao_servico);
            $preco_final = $servico["preco_customizado"] ?: $servico["preco"];
            $profissional = $servico["profissional_nome"];
        }
    } catch (Exception $e) {
        error_log("Erro ao carregar serviço: " . $e->getMessage());
        $erro = "Erro ao carregar dados.";
    }
}

// ============================================================
// GERAR SEMANA DE DATAS PARA EXIBIÇÃO
// ============================================================
$dias_exibir = [];
if (!$erro && $servico) {
    $inicio_semana = new DateTime($data_base);
    $inicio_semana->modify('this week');
    $dias_semana_nomes = ["Dom", "Seg", "Ter", "Qua", "Qui", "Sex", "Sáb"];

    for ($i = 0; $i < 7; $i++) {
        $data_obj = clone $inicio_semana;
        $data_obj->modify("+$i days");
        $data_atual = $data_obj->format("Y-m-d");
        $dia_semana = (int)$data_obj->format("w");
        $passado = ($data_atual < $data_hoje);

        // Verificar liberação
        $consulta = $conexao->prepare("
            SELECT COUNT(*) FROM liberacao_agenda
            WHERE profissional_id = :pid
              AND :data >= data_inicio
              AND :data <= data_fim
        ");
        $consulta->execute([":pid" => $profissional_id, ":data" => $data_atual]);
        $liberada = (int)$consulta->fetchColumn() > 0;

        // Buscar horários de trabalho — CAMPO CORRIGIDO: ultimo_horario
        $consulta = $conexao->prepare("
            SELECT trabalha, horario_inicio, ultimo_horario, inicio_almoco, fim_almoco
            FROM horarios
            WHERE profissional_id = :pid AND dia_semana = :dia LIMIT 1
        ");
        $consulta->execute([":pid" => $profissional_id, ":dia" => $dia_semana]);
        $jornada = $consulta->fetch(PDO::FETCH_ASSOC);

        $trabalha = $jornada && (int)$jornada["trabalha"] === 1;
        $horarios_gerados = [];

        if ($trabalha && $jornada["horario_inicio"] && $jornada["ultimo_horario"]) {
            $inicio = new DateTime($jornada["horario_inicio"]);
            $fim = new DateTime($jornada["ultimo_horario"]);
            $intervalo = new DateInterval("PT{$duracao_servico}M");

            while ($inicio < $fim) {
                $horario_texto = $inicio->format("H:i");

                // Verificar intervalo de almoço
                $em_almoco = false;
                if ($jornada["inicio_almoco"] && $jornada["fim_almoco"]) {
                    $h_almoco_i = new DateTime($jornada["inicio_almoco"]);
                    $h_almoco_f = new DateTime($jornada["fim_almoco"]);
                    $em_almoco = ($inicio >= $h_almoco_i && $inicio < $h_almoco_f);
                }

                // Verificar agendamento existente
                $consulta = $conexao->prepare("
                    SELECT id FROM agendamentos
                    WHERE profissional_id = :pid
                      AND data_agendamento = :data
                      AND horario = :horario
                      AND status <> 'cancelado'
                    LIMIT 1
                ");
                $consulta->execute([
                    ":pid" => $profissional_id,
                    ":data" => $data_atual,
                    ":horario" => $horario_texto
                ]);
                $agendado = $consulta->rowCount() > 0;

                // Verificar bloqueio
                $consulta = $conexao->prepare("
                    SELECT id FROM bloqueios_agenda
                    WHERE profissional_id = :pid
                      AND data_bloqueio = :data
                      AND :horario >= horario_inicio
                      AND :horario < horario_fim
                    LIMIT 1
                ");
                $consulta->execute([
                    ":pid" => $profissional_id,
                    ":data" => $data_atual,
                    ":horario" => $horario_texto
                ]);
                $bloqueado = $consulta->rowCount() > 0;

                // Definir classe e status
                if ($passado) {
                    $classe = "indisponivel-passado";
                } elseif (!$liberada) {
                    $classe = "nao-liberado";
                } elseif ($em_almoco || $bloqueado) {
                    $classe = "indisponivel";
                } elseif ($agendado) {
                    $classe = "agendado";
                } else {
                    $classe = "disponivel";
                }

                $horarios_gerados[] = [
                    "horario" => $horario_texto,
                    "classe" => $classe,
                    "link" => (!$passado && $liberada && !$agendado && !$bloqueado && !$em_almoco)
                        ? "confirmar_agendamento.php?servico_id={$servico_id}&profissional_id={$profissional_id}&data={$data_atual}&horario={$horario_texto}"
                        : null
                ];

                $inicio->add($intervalo);
            }
        }

        $dias_exibir[] = [
            "data" => $data_atual,
            "dia_nome" => $dias_semana_nomes[$dia_semana],
            "dia_numero" => $data_obj->format("d/m"),
            "liberada" => $liberada,
            "trabalha" => $trabalha,
            "passado" => $passado,
            "horarios" => $horarios_gerados
        ];
    }
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
<body class="pagina-cliente pagina-agenda">
    <header class="cabecalho-cliente">
        <div class="logo-cliente"><h1>Estética</h1></div>
        <nav class="menu-cliente">
            <a href="painel_cliente.php">Início</a>
            <a href="servicos.php" class="ativo">Serviços</a>
            <a href="meus_agendamentos.php">Meus Agendamentos</a>
            <a href="logout.php" class="menu-sair">Sair</a>
        </nav>
    </header>

    <main class="agenda-container">
        <?php if ($erro): ?>
            <div class="agenda-cabecalho">
                <h2>Escolha seu horário</h2>
                <div class="agenda-mensagem erro">
                    <?= htmlspecialchars($erro, ENT_QUOTES, "UTF-8") ?>
                </div>
                <a href="servicos.php" class="botao-voltar">Voltar aos serviços</a>
            </div>
        <?php else: ?>
            <div class="agenda-cabecalho">
                <h2>Escolha seu horário</h2>
                <div class="agenda-resumo">
                    <p><strong>Profissional:</strong> <?= htmlspecialchars($profissional, ENT_QUOTES, "UTF-8") ?></p>
                    <p><strong>Serviço:</strong> <?= htmlspecialchars($servico["nome_servico"], ENT_QUOTES, "UTF-8") ?></p>
                    <p><strong>Duração:</strong> <?= htmlspecialchars($duracao_exibicao, ENT_QUOTES, "UTF-8") ?></p>
                    <p><strong>Valor:</strong> R$ <?= number_format((float)$preco_final, 2, ",", ".") ?></p>
                </div>

                <div class="agenda-legenda">
                    <div class="legenda-item">
                        <span class="legenda-cor cor-disponivel"></span>
                        <span>Horário disponível</span>
                    </div>
                    <div class="legenda-item">
                        <span class="legenda-cor cor-agendado"></span>
                        <span>Horário já agendado</span>
                    </div>
                    <div class="legenda-item">
                        <span class="legenda-cor cor-nao-liberado"></span>
                        <span>Horários ainda não liberados</span>
                    </div>
                    <div class="legenda-item">
                        <span class="legenda-cor cor-passado"></span>
                        <span>Data passada</span>
                    </div>
                </div>
            </div>

            <div class="agenda-grade">
                <?php foreach ($dias_exibir as $dia): ?>
                    <div class="agenda-dia">
                        <div class="dia-cabecalho">
                            <div class="dia-nome"><?= $dia["dia_nome"] ?></div>
                            <div class="dia-data"><?= $dia["dia_numero"] ?></div>
                        </div>
                        <div class="dia-horarios">
                            <?php if (!$dia["trabalha"]): ?>
                                <div class="horario-sem-horarios">Não atende</div>
                            <?php elseif (empty($dia["horarios"])): ?>
                                <div class="horario-sem-horarios">Sem horários disponíveis</div>
                            <?php else: ?>
                                <?php foreach ($dia["horarios"] as $h): ?>
                                    <?php if ($h["link"]): ?>
                                        <a href="<?= $h["link"] ?>" class="horario-btn disponivel">
                                            <?= htmlspecialchars($h["horario"], ENT_QUOTES, "UTF-8") ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="horario-btn <?= $h["classe"] ?>">
                                            <?= htmlspecialchars($h["horario"], ENT_QUOTES, "UTF-8") ?>
                                        </span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <a href="servicos.php" class="botao-voltar">Voltar aos serviços</a>
        <?php endif; ?>
    </main>
</body>
</html>
