
<?php
// ============================================================
// INICIAR SESSÃO, CONEXÃO E VALIDAR O ACESSO
// ============================================================

// Inicia a sessão para identificar a cliente autenticada.
session_start();

// Carrega a conexão PDO com o banco de dados.
require_once("conexao.php");

// Permite acesso somente para clientes autenticadas.
if (
    !isset($_SESSION["usuario_id"]) ||
    ($_SESSION["usuario_tipo"] ?? "") !== "cliente"
) {
    header("Location: login.php");
    exit();
}

// Armazena o ID da cliente logada.
$cliente_id = (int)$_SESSION["usuario_id"];

// Define o fuso horário utilizado pelo sistema.
date_default_timezone_set("America/Sao_Paulo");

// ============================================================
// RECEBER E VALIDAR O SERVIÇO E A PROFISSIONAL
// ============================================================

// Recebe os identificadores enviados por servicos.php.
$servico_id = filter_input(INPUT_GET, "servico_id", FILTER_VALIDATE_INT);
$profissional_id = filter_input(INPUT_GET, "profissional_id", FILTER_VALIDATE_INT);

// Guarda mensagens para apresentar na página.
$erro = "";
$mensagem = "";
$servico = null;
$horarios_disponiveis = [];

// Recebe a data escolhida ou utiliza a data atual.
$data_selecionada = $_GET["data"] ?? date("Y-m-d");

// Valida o formato da data antes de consultar o banco.
$data_objeto = DateTime::createFromFormat("!Y-m-d", $data_selecionada);

// Impede datas inexistentes ou anteriores ao dia atual.
if (
    !$data_objeto ||
    $data_objeto->format("Y-m-d") !== $data_selecionada ||
    $data_selecionada < date("Y-m-d")
) {
    $data_selecionada = date("Y-m-d");
    $erro = "Selecione uma data válida, a partir de hoje.";
}

// ============================================================
// FUNÇÃO PARA CONVERTER A DURAÇÃO DO SERVIÇO EM MINUTOS
// ============================================================

// Aceita formatos como "2h", "1h30", "1h 30min" e "45min".
// Caso a duração esteja em um formato desconhecido, retorna zero.
function converterDuracaoMinutos($duracao)
{
    // Normaliza o texto para facilitar a leitura da duração.
    $texto = mb_strtolower(trim((string)$duracao));

    // Reconhece formatos como "1h30", "1h 30min" e "2h".
    if (preg_match('/^(\d+)\s*h(?:\s*(\d+)\s*(?:min)?)?$/u', $texto, $partes)) {
        $horas = (int)$partes[1];
        $minutos = isset($partes[2]) ? (int)$partes[2] : 0;

        return ($horas * 60) + $minutos;
    }

    // Reconhece durações informadas somente em minutos.
    if (preg_match('/^(\d+)\s*min$/u', $texto, $partes)) {
        return (int)$partes[1];
    }

    // Não inventa uma duração quando o formato é desconhecido.
    return 0;
}

// ============================================================
// BUSCAR O SERVIÇO E CONFIRMAR SUA VINCULAÇÃO
// ============================================================

try {
    // Confirma que o serviço pertence à profissional selecionada.
    // Também verifica se ambos estão ativos.
    if (!$servico_id || !$profissional_id) {
        throw new Exception("Serviço ou profissional não informado.");
    }

    $consulta = $conexao->prepare("
        SELECT
            s.id,
            s.nome_servico,
            s.preco,
            s.duracao,
            u.id AS profissional_id,
            u.nome AS profissional_nome
        FROM servicos s
        INNER JOIN vinculacao v ON v.servico_id = s.id
        INNER JOIN usuarios u ON u.id = v.profissional_id
        WHERE s.id = :servico_id
          AND u.id = :profissional_id
          AND s.status = 'ativo'
          AND u.tipo_usuario = 'profissional'
          AND u.status = 'ativo'
        LIMIT 1
    ");

    $consulta->execute([
        ":servico_id" => $servico_id,
        ":profissional_id" => $profissional_id
    ]);

    $servico = $consulta->fetch(PDO::FETCH_ASSOC);

    // Impede acesso a serviços inexistentes ou não vinculados.
    if (!$servico) {
        throw new Exception("O serviço não está disponível para esta profissional.");
    }

    // Converte a duração cadastrada em minutos.
    $duracao_servico = converterDuracaoMinutos($servico["duracao"]);

    // Exige uma duração reconhecida para calcular os conflitos.
    if ($duracao_servico <= 0) {
        throw new Exception(
            "A duração deste serviço precisa ser conferida no cadastro."
        );
    }

    // ========================================================
    // VERIFICAR SE A DATA FOI LIBERADA PELA PROFISSIONAL
    // ========================================================

    // A data só fica disponível se estiver dentro de uma liberação.
    $consulta = $conexao->prepare("
        SELECT COUNT(*)
        FROM liberacao_agenda
        WHERE profissional_id = :pid
          AND data_inicio <= :data_inicio
          AND data_fim >= :data_fim
    ");

    $consulta->execute([
        ":pid" => $profissional_id,
        ":data_inicio" => $data_selecionada,
        ":data_fim" => $data_selecionada
    ]);

    $data_liberada = (int)$consulta->fetchColumn() > 0;

    // ========================================================
    // CONSULTAR A JORNADA DO DIA DA SEMANA
    // ========================================================

    // Obtém o dia da semana: 0 é domingo e 6 é sábado.
    $dia_semana = (int)date("w", strtotime($data_selecionada));

    // Busca os horários definidos pela profissional.
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

    // Não presume que a profissional trabalha sem configuração salva.
    if (!$data_liberada) {
        $mensagem = "Esta data ainda não foi liberada para agendamentos.";
    } elseif (!$jornada || (int)$jornada["trabalha"] !== 1) {
        $mensagem = "A profissional não atende neste dia.";
    } else {
        // ====================================================
        // BUSCAR BLOQUEIOS DA DATA SELECIONADA
        // ====================================================

        // Carrega todos os períodos bloqueados para esta profissional.
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
        // BUSCAR AGENDAMENTOS QUE OCUPAM A DATA
        // ====================================================

        // Recupera as durações dos atendimentos já cadastrados.
        // Agendamentos cancelados não ocupam mais a agenda.
        $consulta = $conexao->prepare("
            SELECT a.horario, a.status, s.duracao
            FROM agendamentos a
            LEFT JOIN servicos s ON s.id = a.servico_id
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
        // CALCULAR OS HORÁRIOS DISPONÍVEIS
        // ====================================================

        // Converte os limites do expediente para minutos após meia-noite.
        $inicio_expediente = strtotime($jornada["horario_inicio"]);
        $ultimo_atendimento = strtotime($jornada["ultimo_horario"]);

        // Prepara os limites do intervalo de almoço.
        $inicio_almoco = !empty($jornada["inicio_almoco"])
            ? strtotime($jornada["inicio_almoco"])
            : null;

        $fim_almoco = !empty($jornada["fim_almoco"])
            ? strtotime($jornada["fim_almoco"])
            : null;

        // Gera opções em intervalos de 30 minutos.
        // A duração completa do serviço deve caber no expediente.
        for (
            $inicio = $inicio_expediente;
            $inicio <= $ultimo_atendimento;
            $inicio += 30 * 60
        ) {
            // Calcula quando o serviço selecionado terminaria.
            $fim = $inicio + ($duracao_servico * 60);

            // Ignora horários anteriores ao momento atual.
            if (
                $data_selecionada === date("Y-m-d") &&
                $inicio <= time()
            ) {
                continue;
            }

            // Converte os horários candidatos para formato HH:MM:SS.
            $hora_inicio = date("H:i:s", $inicio);
            $hora_fim = date("H:i:s", $fim);

            // O último horário cadastrado representa o limite de início.
            // Não oferece atendimento que ultrapasse esse limite.
            if ($inicio > $ultimo_atendimento) {
                continue;
            }

            // Verifica se o atendimento invade o intervalo de almoço.
            if (
                $inicio_almoco !== null &&
                $fim_almoco !== null &&
                $inicio < $fim_almoco &&
                $fim > $inicio_almoco
            ) {
                continue;
            }

            // Verifica se o atendimento coincide com algum bloqueio.
            $conflito_bloqueio = false;

            foreach ($bloqueios_dia as $bloqueio) {
                $bloqueio_inicio = strtotime($bloqueio["horario_inicio"]);
                $bloqueio_fim = strtotime($bloqueio["horario_fim"]);

                // Existe conflito quando os intervalos se sobrepõem.
                if (
                    $inicio < $bloqueio_fim &&
                    $fim > $bloqueio_inicio
                ) {
                    $conflito_bloqueio = true;
                    break;
                }
            }

            // Descarta horários que coincidem com bloqueios.
            if ($conflito_bloqueio) {
                continue;
            }

            // Verifica conflitos com os agendamentos existentes.
            $conflito_agendamento = false;

            foreach ($agendamentos_dia as $agendamento) {
                // Converte o início do atendimento existente.
                $inicio_existente = strtotime($agendamento["horario"]);

                // Obtém a duração do serviço já agendado.
                $duracao_existente = converterDuracaoMinutos(
                    $agendamento["duracao"] ?? ""
                );

                // Se a duração antiga não for reconhecida,
                // não presume que o atendimento dura zero minutos.
                if ($duracao_existente <= 0) {
                    $conflito_agendamento = true;
                    break;
                }

                // Calcula o término do atendimento existente.
                $fim_existente = $inicio_existente + ($duracao_existente * 60);

                // Descarta opções que se sobrepõem a outra reserva.
                if (
                    $inicio < $fim_existente &&
                    $fim > $inicio_existente
                ) {
                    $conflito_agendamento = true;
                    break;
                }
            }

            // Adiciona somente horários sem conflitos conhecidos.
            if (!$conflito_agendamento) {
                $horarios_disponiveis[] = date("H:i", $inicio);
            }
        }

        // Informa quando não restarem horários livres.
        if (empty($horarios_disponiveis)) {
            $mensagem = "Não há horários disponíveis nesta data.";
        }
    }
} catch (Exception $erro_agenda) {
    // Registra detalhes técnicos no log sem expor dados internos.
    error_log("Erro ao carregar agenda: " . $erro_agenda->getMessage());

    // Apresenta uma mensagem compreensível para a cliente.
    $erro = $erro_agenda->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <!-- Configura os caracteres e a adaptação para celulares. -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Define o título da página no navegador. -->
    <title>Escolher Horário | TCC Estética</title>

    <!-- Reutiliza o estilo global do projeto. -->
    <link rel="stylesheet" href="style.css">

</head>

<body class="pagina-cliente pagina-agenda-cliente">
    <!-- Reutiliza o cabeçalho da área da cliente. -->
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

    <!-- Área principal da consulta de disponibilidade. -->
    <main class="agenda-container">
        <section class="agenda-card">
            <h2>Escolha seu horário</h2>

            <?php if ($erro): ?>
                <!-- Exibe erros de validação sem mostrar informações técnicas. -->
                <div class="agenda-mensagem agenda-erro">
                    <?= htmlspecialchars($erro, ENT_QUOTES, "UTF-8") ?>
                </div>

                <a href="servicos.php" class="agenda-botao">
                    Voltar aos serviços
                </a>

            <?php elseif ($servico): ?>
                <!-- Resume o serviço e a profissional selecionados. -->
                <div class="agenda-resumo">
                    <strong>Serviço:</strong>
                    <?= htmlspecialchars($servico["nome_servico"], ENT_QUOTES, "UTF-8") ?>
                    <br>

                    <strong>Profissional:</strong>
                    <?= htmlspecialchars($servico["profissional_nome"], ENT_QUOTES, "UTF-8") ?>
                    <br>

                    <strong>Duração:</strong>
                    <?= htmlspecialchars($servico["duracao"], ENT_QUOTES, "UTF-8") ?>
                    <br>

                    <strong>Valor:</strong>
                    R$ <?= number_format((float)$servico["preco"], 2, ",", ".") ?>
                </div>

                <!-- Permite consultar outra data sem perder o serviço escolhido. -->
                <form method="GET" action="agenda.php" class="agenda-formulario">
                    <input
                        type="hidden"
                        name="servico_id"
                        value="<?= (int)$servico_id ?>"
                    >

                    <input
                        type="hidden"
                        name="profissional_id"
                        value="<?= (int)$profissional_id ?>"
                    >

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

                    <button type="submit" class="agenda-botao">
                        Consultar horários
                    </button>
                </form>

                <h3>Horários disponíveis</h3>

                <?php if ($mensagem): ?>
                    <!-- Explica por que não há horários para a data selecionada. -->
                    <div class="agenda-mensagem">
                        <?= htmlspecialchars($mensagem, ENT_QUOTES, "UTF-8") ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($horarios_disponiveis)): ?>
                    <!-- Apresenta somente os horários que passaram pelas verificações. -->
                    <div class="agenda-horarios">
                        <?php foreach ($horarios_disponiveis as $horario): ?>
                            <div class="agenda-horario">
                                <?= htmlspecialchars($horario, ENT_QUOTES, "UTF-8") ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <p class="agenda-mensagem">
                        A disponibilidade será verificada novamente antes de confirmar o agendamento.
                    </p>
                <?php endif; ?>

            <?php endif; ?>
        </section>
    </main>
</body>
</html>