<?php
// Inicia a sessão de usuário no navegador.
// Reconhece a profissional que está acessando a página, mantendo seus dados de login disponíveis
session_start();

// Inclui o arquivo "conexao.php" que contém as configurações e instruções para conectar ao
// banco de dados MySQL utilizando PDO (uma forma segura e recomendada de acessar bancos de dados em PHP).
require_once("conexao.php");

/* ==============================================================================
   SEGURANÇA — VERIFICAÇÃO DE PERMISSÃO DE ACESSO
   ------------------------------------------------------------------------------
   Esta seção garante que apenas profissionais logadas tenham acesso à página.
   Se alguém não autorizada tentar acessar diretamente pelo endereço, será
   enviada para a página de login e não conseguirá ver o conteúdo.
   ============================================================================== */

// Verifica se existe um identificador de usuário na sessão (se a pessoa está logada)
// e se o tipo de usuário cadastrado é "profissional". Se faltar qualquer um dos dois,
// significa que a pessoa não tem permissão para acessar esta página.
if (!isset($_SESSION["usuario_id"]) || $_SESSION["usuario_tipo"] !== "profissional") {
    // Redireciona o navegador para a página de login.
    header("Location: login.php");
    // Interrompe a execução do código imediatamente, garantindo que o restante
    // da página não seja carregado para quem não tem permissão.
    exit();
}

// Armazena o número de identificação da profissional que está logada no sistema.
// A função (int) converte o valor recebido em número inteiro como medida de segurança,
// evitando que textos ou códigos maliciosos sejam interpretados como identificador.
$profissional_id = (int)$_SESSION["usuario_id"];

/* ==============================================================================
   DEFINIR MÊS E ANO QUE SERÃO EXIBIDOS NO CALENDÁRIO
   ------------------------------------------------------------------------------
   Esta seção prepara qual mês e ano serão mostrados no calendário. Por padrão,
   mostra o mês atual, mas permite que a profissional navegue para outros meses
   clicando nas setas ou alterando o endereço da página.
   ============================================================================== */

// Define o fuso horário que será usado em todas as funções de data e hora do sistema.
// É importante definir para evitar diferenças de horário entre o servidor e o Brasil.
date_default_timezone_set("America/Sao_Paulo");

// Obtém o mês atual do sistema no formato de dois dígitos (ex: "09" para setembro).
$mes_atual = date("m");

// Obtém o ano atual do sistema com quatro dígitos (ex: "2026").
$ano_atual = date("Y");

// Verifica se a profissional selecionou algum mês específico através do endereço da página.
// Se não informou, usa o mês atual como valor padrão. O operador ?? significa "se estiver vazio, use este valor".
$mes = $_GET["mes"] ?? $mes_atual;

// Verifica se a profissional selecionou algum ano específico. Se não informou, usa o ano atual.
$ano = $_GET["ano"] ?? $ano_atual;

// Define qual aba será exibida quando a página carregar. Se não for informada, mostra o calendário.
$aba = $_GET["aba"] ?? "calendario";

// Garante que o mês seja um valor válido entre 1 e 12. Converte para número inteiro, limita o intervalo,
// e formata com dois dígitos (ex: 1 vira "01"). Isso evita que valores inválidos quebrem o calendário.
$mes = str_pad(min(max((int)$mes, 1), 12), 2, "0", STR_PAD_LEFT);

// Garante que o ano seja um valor dentro de um intervalo razoável, evitando números absurdos.
$ano = min(max((int)$ano, 2020), 2100);

/* ==============================================================================
   PROCESSAMENTO DE AÇÕES — SALVAR HORÁRIOS, BLOQUEIOS E LIBERAÇÕES
   ------------------------------------------------------------------------------
   Aqui são recebidos e processados todos os formulários enviados pela profissional.
   Dependendo do botão que ela clicou, uma ação diferente será executada no banco.
   ============================================================================== */

// Variável que guarda mensagens de confirmação quando uma operação dá certo.
$mensagem_sucesso = "";

// Variável que guarda mensagens de explicação quando algo dá errado.
$mensagem_erro = "";

// Verifica se o formulário foi enviado (método POST) e se existe uma ação definida.
// O método POST significa que os dados foram enviados de forma segura, não aparecendo na URL.
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["acao"])) {
    
    // Inicia um bloco de proteção. Se qualquer erro acontecer dentro dele,
    // o sistema captura o erro e mostra uma mensagem em vez de travar a página.
    try {
        
        // Identifica qual operação a profissional deseja executar.
        // O valor vem de um campo oculto em cada formulário enviado.
        $acao = $_POST["acao"];

        // ----------------------------------------------------------------------
        // AÇÃO 1: SALVAR HORÁRIOS PADRÃO DE ATENDIMENTO
        // ----------------------------------------------------------------------
        // Executa quando a profissional configura seus horários de trabalho
        // normais de cada dia da semana (entrada, saída e horário de almoço).
        if ($acao === "salvar_horarios") {

            // Repete o processo para cada dia da semana, de 0 (Domingo) até 6 (Sábado).
            for ($dia_semana = 0; $dia_semana <= 6; $dia_semana++) {

                // Verifica se a caixa "Trabalha" está marcada para este dia.
                // Se estiver marcada, recebe 1; se não estiver, recebe 0.
                $trabalha = isset($_POST["trabalha"][$dia_semana]) ? 1 : 0;

                // Captura o horário de início de atendimento digitado no formulário,
                // removendo espaços desnecessários. Se não foi preenchido, fica vazio.
                $horario_inicio = trim($_POST["horario_inicio"][$dia_semana] ?? "");

                // Captura o horário do último atendimento do dia.
                $ultimo_horario = trim($_POST["ultimo_horario"][$dia_semana] ?? "");

                // Captura o horário de início do almoço.
                $inicio_almoco = trim($_POST["inicio_almoco"][$dia_semana] ?? "");

                // Captura o horário de retorno do almoço.
                $fim_almoco = trim($_POST["fim_almoco"][$dia_semana] ?? "");

                // Converte o número do dia da semana para formato inteiro.
                $dia_semana_int = (int)$dia_semana;

                // Verifica se JÁ existe um cadastro de horários para este dia da semana.
                // Consulta o banco procurando um registro com o ID da profissional e o dia.
                $verifica = $conexao->prepare("SELECT id FROM horarios WHERE profissional_id = :pid AND dia_semana = :dia LIMIT 1");
                $verifica->execute([":pid" => $profissional_id, ":dia" => $dia_semana_int]);
                $existe = $verifica->fetch();

                // Se já existe cadastro, atualiza os dados antigos com os novos.
                // Se NÃO existe cadastro, cria um registro novo no banco.
                if ($existe) {
                    $comando = $conexao->prepare("UPDATE horarios SET trabalha = :trabalha, horario_inicio = :hi, ultimo_horario = :uh, inicio_almoco = :ia, fim_almoco = :fa WHERE profissional_id = :pid AND dia_semana = :dia");
                } else {
                    $comando = $conexao->prepare("INSERT INTO horarios (profissional_id, dia_semana, trabalha, horario_inicio, ultimo_horario, inicio_almoco, fim_almoco) VALUES (:pid, :dia, :trabalha, :hi, :uh, :ia, :fa)");
                }

                // Executa o comando no banco de dados. Se algum campo ficou vazio,
                // substitui por valor NULO no banco, que é o jeito correto de indicar
                // "sem valor definido" em bancos de dados.
                $comando->execute([
                    ":pid" => $profissional_id, ":dia" => $dia_semana_int, ":trabalha" => $trabalha,
                    ":hi" => $horario_inicio ?: null, ":uh" => $ultimo_horario ?: null,
                    ":ia" => $inicio_almoco ?: null, ":fa" => $fim_almoco ?: null
                ]);
            }

            // Guarda mensagem de confirmação para mostrar à profissional.
            $mensagem_sucesso = "Horários salvos!";
            // Retorna para a aba de horários após salvar, mostrando os dados atualizados.
            $aba = "horarios";
        }

        // ----------------------------------------------------------------------
        // AÇÃO 2: BLOQUEAR UM DIA OU PERÍODO ESPECÍFICO
        // ----------------------------------------------------------------------
        // Executa quando a profissional deseja bloquear um dia inteiro ou apenas
        // um horário específico para não receber agendamentos (folga, feriado, etc).
        elseif ($acao === "bloquear_dia") {

            // Recebe a data que será bloqueada, removendo espaços desnecessários.
            $data_bloqueio = trim($_POST["data_bloqueio"] ?? "");

            // Recebe o motivo do bloqueio. Se não foi preenchido, usa "Folga" como padrão.
            $motivo = trim($_POST["motivo"] ?? "Folga");

            // Verifica se a profissional quer bloquear o dia inteiro ou só um horário.
            $tipo_bloqueio = $_POST["tipo_bloqueio"] ?? "dia_inteiro";

            // Validação: a data é obrigatória. Se não foi informada, mostra erro.
            if (!$data_bloqueio) throw new Exception("Informe a data.");

            // Se for bloquear apenas um horário, captura e valida os horários de início e fim.
            if ($tipo_bloqueio === "horario") {
                $horario_inicio = trim($_POST["horario_inicio_bloqueio"] ?? "");
                $horario_fim = trim($_POST["horario_fim_bloqueio"] ?? "");
                if (!$horario_inicio || !$horario_fim) throw new Exception("Informe o horário.");
            } else {
                // Se for dia inteiro, define horário de 00h00 até 23h59 para cobrir o dia todo.
                $horario_inicio = "00:00";
                $horario_fim = "23:59";
            }

            // Insere o bloqueio no banco de dados, vinculando à profissional logada.
            $comando = $conexao->prepare("INSERT INTO bloqueios_agenda (profissional_id, data_bloqueio, horario_inicio, horario_fim, motivo) VALUES (:pid, :data, :hi, :hf, :motivo)");
            $comando->execute([":pid" => $profissional_id, ":data" => $data_bloqueio, ":hi" => $horario_inicio, ":hf" => $horario_fim, ":motivo" => $motivo]);

            // Mensagem de confirmação e retorno para a aba de bloqueios.
            $mensagem_sucesso = "Bloqueio registrado!";
            $aba = "bloqueios";
        }

        // ----------------------------------------------------------------------
        // AÇÃO 3: EXCLUIR UM BLOQUEIO JÁ EXISTENTE
        // ----------------------------------------------------------------------
        // Executa quando a profissional apaga um bloqueio que havia registrado.
        elseif ($acao === "excluir_bloqueio") {

            // Recebe o número de identificação do bloqueio que será removido.
            // Converte para número inteiro por segurança.
            $bloqueio_id = (int)($_POST["bloqueio_id"] ?? 0);

            // Apaga o bloqueio do banco, garantindo que a profissional só consiga
            // excluir seus próprios bloqueios (verificando também o ID dela).
            $conexao->prepare("DELETE FROM bloqueios_agenda WHERE id = :id AND profissional_id = :pid")
                ->execute([":id" => $bloqueio_id, ":pid" => $profissional_id]);

            // Mensagem de confirmação e retorno para a aba de bloqueios.
            $mensagem_sucesso = "Bloqueio removido!";
            $aba = "bloqueios";
        }

        // ----------------------------------------------------------------------
        // AÇÃO 4: LIBERAR PERÍODO PARA RECEBIMENTO DE AGENDAMENTOS
        // ----------------------------------------------------------------------
        // Por padrão, novos meses NÃO ficam disponíveis para agendamento.
        // Esta ação libera um mês inteiro ou datas específicas para que clientes
        // consigam fazer agendamentos naquele período.
        elseif ($acao === "liberar_agenda") {

            // Verifica se vai liberar mês inteiro ou dias separados.
            $tipo_liberacao = $_POST["tipo_liberacao"] ?? "";

            // Recebe o mês e ano informados no formulário.
            $mes_liberar = trim($_POST["mes_liberar"] ?? "");
            $ano_liberar = trim($_POST["ano_liberar"] ?? "");

            // Se for liberação de mês inteiro, calcula a data do primeiro dia e do último dia do mês.
            if ($tipo_liberacao === "mes" && $mes_liberar && $ano_liberar) {
                $data_inicio = "$ano_liberar-$mes_liberar-01";
                $data_fim = date("Y-m-t", strtotime($data_inicio)); // "t" retorna o último dia daquele mês
                $valor = "$mes_liberar/$ano_liberar";
                $tipo_banco = "mes";
            }
            // Se for liberação de dias separados, organiza as datas informadas.
            elseif ($tipo_liberacao === "semana" && !empty($_POST["dias_liberar"]) && is_array($_POST["dias_liberar"])) {
                // Remove campos vazios da lista de datas.
                $dias = array_filter($_POST["dias_liberar"]);
                if (empty($dias)) throw new Exception("Selecione pelo menos um dia.");
                // Ordena as datas da mais antiga para a mais nova.
                sort($dias);
                $data_inicio = reset($dias); // pega a primeira data
                $data_fim = end($dias);     // pega a última data
                $valor = count($dias) . " dias selecionados";
                $tipo_banco = "semana";
            } else {
                throw new Exception("Selecione o tipo de liberação.");
            }

            // Insere a liberação no banco de dados.
            $comando = $conexao->prepare("INSERT INTO liberacao_agenda (profissional_id, tipo, valor, data_inicio, data_fim) VALUES (:pid, :tipo, :valor, :de, :ate)");
            $comando->execute([
                ":pid" => $profissional_id,
                ":tipo" => $tipo_banco,
                ":valor" => $valor,
                ":de" => $data_inicio,
                ":ate" => $data_fim
            ]);

            // Mensagem de confirmação e retorno para a aba de liberação.
            $mensagem_sucesso = "Período liberado para agendamentos!";
            $aba = "liberar";
        }

        // ----------------------------------------------------------------------
        // AÇÃO 5: CANCELAR UM AGENDAMENTO
        // ----------------------------------------------------------------------
        // Executa quando a profissional cancela um atendimento já marcado.
        // Em vez de apagar o registro, apenas altera seu status para "cancelado",
        // mantendo o registro no histórico do sistema.
        elseif ($acao === "cancelar_agendamento") {

            // Recebe o número de identificação do agendamento.
            $agendamento_id = (int)($_POST["agendamento_id"] ?? 0);

            // Atualiza o status do agendamento no banco para "cancelado",
            // garantindo que a profissional só consiga cancelar seus próprios agendamentos.
            $conexao->prepare("UPDATE agendamentos SET status = 'cancelado' WHERE id = :id AND profissional_id = :pid")
                ->execute([":id" => $agendamento_id, ":pid" => $profissional_id]);

            // Mensagem de confirmação.
            $mensagem_sucesso = "Agendamento cancelado!";
        }

    // Se qualquer erro acontecer dentro do bloco "try", captura o erro e mostra na tela.
    } catch (Exception $erro) {
        $mensagem_erro = "erro " . $erro->getMessage();
    }
}

/* ==============================================================================
   CARREGAR DADOS DO BANCO PARA EXIBIR NA PÁGINA
   ------------------------------------------------------------------------------
   Antes de desenhar a página, busca todas as informações necessárias no banco
   para que o calendário e as abas apareçam com os dados já salvos.
   ============================================================================== */

// Inicializa a lista de horários com valores padrão antes de consultar o banco.
// Se a profissional ainda não tiver cadastrado horários, estes valores aparecem como sugestão.
$horarios_dia = [];
for ($i = 0; $i <= 6; $i++) {
    $horarios_dia[$i] = ["trabalha" => 0, "horario_inicio" => "08:00", "ultimo_horario" => "19:00", "inicio_almoco" => "12:00", "fim_almoco" => "13:00"];
}

// Busca no banco os horários personalizados da profissional logada.
try {
    $consulta = $conexao->prepare("SELECT dia_semana, trabalha, horario_inicio, ultimo_horario, inicio_almoco, fim_almoco FROM horarios WHERE profissional_id = :pid");
    $consulta->execute([":pid" => $profissional_id]);

    // Para cada horário encontrado, substitui o valor padrão pelo valor salvo no banco.
    foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $dia = (int)$h["dia_semana"];
        if ($dia >= 0 && $dia <= 6) {
            $horarios_dia[$dia] = [
                "trabalha" => (int)$h["trabalha"],
                "horario_inicio" => $h["horario_inicio"] ?? "08:00",
                "ultimo_horario" => $h["ultimo_horario"] ?? "19:00",
                "inicio_almoco" => $h["inicio_almoco"] ?? "12:00",
                "fim_almoco" => $h["fim_almoco"] ?? "13:00"
            ];
        }
    }
} catch (PDOException $e) {
    // Se der erro na consulta, grava o erro no log do servidor em vez de mostrar na tela.
    error_log("Erro ao buscar horários: " . $e->getMessage());
}

// Busca todos os agendamentos do mês que está sendo visualizado.
// Junta dados da tabela de agendamentos com nome do cliente e nome do serviço.
$agendamentos_por_dia = [];
try {
    $consulta = $conexao->prepare("SELECT a.id, a.data_agendamento, a.horario, a.status, c.nome AS nome_cliente, s.nome_servico FROM agendamentos a LEFT JOIN usuarios c ON a.cliente_id = c.id LEFT JOIN servicos s ON a.servico_id = s.id WHERE a.profissional_id = :pid AND YEAR(a.data_agendamento) = :ano AND MONTH(a.data_agendamento) = :mes ORDER BY a.data_agendamento, a.horario");
    $consulta->execute([":pid" => $profissional_id, ":ano" => $ano, ":mes" => $mes]);

    // Organiza os agendamentos agrupando por data, para facilitar exibir no calendário.
    while ($ag = $consulta->fetch(PDO::FETCH_ASSOC)) {
        $agendamentos_por_dia[$ag["data_agendamento"]][] = $ag;
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar agendamentos: " . $e->getMessage());
}

// Busca todos os bloqueios registrados no mês atual.
$bloqueios = [];
try {
    $consulta = $conexao->prepare("SELECT id, data_bloqueio, horario_inicio, horario_fim, motivo FROM bloqueios_agenda WHERE profissional_id = :pid AND YEAR(data_bloqueio) = :ano AND MONTH(data_bloqueio) = :mes");
    $consulta->execute([":pid" => $profissional_id, ":ano" => $ano, ":mes" => $mes]);

    // Organiza os bloqueios agrupados por data.
    while ($b = $consulta->fetch(PDO::FETCH_ASSOC)) {
        $bloqueios[$b["data_bloqueio"]][] = $b;
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar bloqueios: " . $e->getMessage());
}

// Busca as últimas liberações de agenda registradas pela profissional.
$liberacoes = [];
try {
    $consulta = $conexao->prepare("SELECT * FROM liberacao_agenda WHERE profissional_id = :pid ORDER BY data_inicio DESC LIMIT 10");
    $consulta->execute([":pid" => $profissional_id]);
    $liberacoes = $consulta->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar liberações: " . $e->getMessage());
}

/* ==============================================================================
   PREPARAR DADOS PARA DESENHAR O CALENDÁRIO
   ------------------------------------------------------------------------------
   Calcula as posições, nomes e quantidades necessárias para montar a tabela
   do calendário corretamente no navegador.
   ============================================================================== */

// Relação dos nomes dos meses em português, usada para mostrar "Setembro de 2026".
$nomes_meses = ["01"=>"Janeiro","02"=>"Fevereiro","03"=>"Março","04"=>"Abril","05"=>"Maio","06"=>"Junho","07"=>"Julho","08"=>"Agosto","09"=>"Setembro","10"=>"Outubro","11"=>"Novembro","12"=>"Dezembro"];

// Nome do mês que está sendo exibido.
$nome_mes = $nomes_meses[$mes] ?? "";

// Descobre em qual dia da semana cai o primeiro dia do mês (0=Domingo, 6=Sábado).
// Usado para saber quantas células vazias desenhar antes do dia 1.
$primeiro_dia_semana = date("w", strtotime("$ano-$mes-01"));

// Calcula quantos dias tem aquele mês (ex: 30 ou 31 dias).
$quantos_dias = date("t", strtotime("$ano-$mes-01"));

// Define o mês anterior para o botão de navegação ◀.
$mes_anterior = $mes == 1 ? 12 : $mes - 1;
$ano_anterior = $mes == 1 ? $ano - 1 : $ano;

// Define o próximo mês para o botão de navegação ▶.
$proximo_mes = $mes == 12 ? 1 : $mes + 1;
$ano_proximo = $mes == 12 ? $ano + 1 : $ano;

// Nomes abreviados dos dias da semana que aparecem no topo do calendário.
$nomes_dias_semana = ["Dom", "Seg", "Ter", "Qua", "Qui", "Sex", "Sáb"];
?>

<!-- ==============================================================================
     ESTRUTURA VISUAL DA PÁGINA — CÓDIGO HTML
     ============================================================================== -->

<!-- Declara que este documento segue o padrão HTML5 -->
<!DOCTYPE html>

<!-- Define o idioma da página como Português do Brasil -->
<html lang="pt-BR">
<head>
    <!-- Define a codificação de caracteres para aceitar acentuação e símbolos brasileiros -->
    <meta charset="UTF-8">
    <!-- Faz o site se adaptar ao tamanho da tela do celular, tablet ou computador -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Define o nome que aparece na aba do navegador -->
    <title>Minha Agenda</title>
    <!-- Conecta o arquivo de estilos que define cores, fontes e formato visual da página -->
    <link rel="stylesheet" href="style.css">
</head>
<body>

<!-- Menu lateral fixo que aparece em todas as páginas do painel da profissional -->
<aside class="sidebar">
    <!-- Área que mostra o nome do sistema e a especialidade da profissional logada -->
    <div class="logo">
        <h1>Estética</h1>
        <!-- Exibe a especialidade salva no cadastro ou "Profissional" se não estiver definida -->
        <!-- htmlspecialchars protege contra códigos maliciosos que poderiam estar salvos no nome -->
        <p><?= htmlspecialchars($_SESSION["especialidade"] ?? "Profissional") ?></p>
    </div>

    <!-- Links de navegação entre as páginas do sistema -->
    <nav class="menu">
        <a href="painel_profissional.php">Início</a>
        <!-- A palavra "ativo" deixa este link destacado, indicando que está na página atual -->
        <a href="minha_agenda.php" class="ativo">Minha Agenda</a>
        <a href="meus_servicos.php">Meus Serviços</a>
        <a href="historico_atendimentos.php">Histórico</a>
        <div class="separador"></div>
        <a href="meu_perfil.php">Meu Perfil</a>
    </nav>

    <!-- Botão para encerrar a sessão e sair do sistema -->
    <a href="logout.php" class="btn-sair">Sair</a>
</aside>

<!-- Conteúdo principal da página -->
<main class="main">
    <!-- Cabeçalho com título e descrição da página -->
    <section class="topo">
        <div>
            <h2>Minha Agenda</h2>
            <p>Gerencie seus horários, bloqueios e períodos liberados</p>
        </div>
        <!-- Mostra a data atual do sistema -->
        <div class="data"><?= date("l, d/m/Y") ?></div>
    </section>

    <!-- Mensagem de confirmação que aparece após salvar algo com sucesso -->
    <?php if ($mensagem_sucesso): ?>
        <div class="alerta alerta-sucesso"><?= htmlspecialchars($mensagem_sucesso) ?></div>
    <?php endif; ?>

    <!-- Mensagem de erro que aparece quando alguma operação falha -->
    <?php if ($mensagem_erro): ?>
        <div class="alerta alerta-erro"><?= htmlspecialchars($mensagem_erro) ?></div>
    <?php endif; ?>

    <!-- Botões de ação principais -->
    <div class="botoes-acao">
        <a href="agendar_cliente.php" class="btn btn-sucesso">Reservar Horário</a>
        <a href="?mes=<?= $mes ?>&ano=<?= $ano ?>&aba=liberar" class="btn btn-primario">Liberar Agendamentos</a>
    </div>

    <!-- Botões que alternam entre as abas da página -->
    <div class="abas-linha">
        <a href="?mes=<?= $mes ?>&ano=<?= $ano ?>&aba=calendario" class="aba-botao <?= $aba==='calendario'?'ativa':'' ?>">Calendário</a>
        <a href="?mes=<?= $mes ?>&ano=<?= $ano ?>&aba=horarios" class="aba-botao <?= $aba==='horarios'?'ativa':'' ?>">Meus Horários</a>
        <a href="?mes=<?= $mes ?>&ano=<?= $ano ?>&aba=bloqueios" class="aba-botao <?= $aba==='bloqueios'?'ativa':'' ?>">Folgas/Bloqueios</a>
        <a href="?mes=<?= $mes ?>&ano=<?= $ano ?>&aba=liberar" class="aba-botao <?= $aba==='liberar'?'ativa':'' ?>">Liberação</a>
    </div>

    <!-- ======================================================================
         ABA 1 — CALENDÁRIO COM VISUALIZAÇÃO DOS DIAS E AGENDAMENTOS
         ====================================================================== -->
    <div class="aba-conteudo <?= $aba==='calendario'?'visivel':'' ?>">
        <div class="calendario">
            <!-- Cabeçalho com botões de navegação entre meses -->
            <div class="calendario-cabecalho">
                <a href="?mes=<?= $mes_anterior ?>&ano=<?= $ano_anterior ?>&aba=calendario">◀</a>
                <h3><?= $nome_mes ?> de <?= $ano ?></h3>
                <a href="?mes=<?= $proximo_mes ?>&ano=<?= $ano_proximo ?>&aba=calendario">▶</a>
            </div>

            <!-- Grade onde os dias do mês serão desenhados -->
            <div class="calendario-grade">
                <!-- Escreve os nomes dos dias da semana no topo da tabela -->
                <?php foreach ($nomes_dias_semana as $n): ?>
                    <div class="calendario-dia-nome"><?= $n ?></div>
                <?php endforeach; ?>

                <!-- Desenha células vazias antes do dia 1, para alinhar o mês no dia correto da semana -->
                <?php for ($i=0; $i<$primeiro_dia_semana; $i++): ?>
                    <div class="calendario-dia calendario-dia-vazio"></div>
                <?php endfor; ?>

                <!-- Laço principal: desenha cada dia do mês -->
                <?php
                for ($dia=1; $dia<=$quantos_dias; $dia++):
                    // Descobre em qual dia da semana cai esta data.
                    $dia_sem = date("w", strtotime("$ano-$mes-".str_pad($dia,2,"0",STR_PAD_LEFT)));
                    // Formata a data no padrão "ano-mês-dia" para usar em comparações.
                    $data = sprintf("%04d-%02d-%02d", $ano, $mes, $dia);
                    // Verifica se esta data é o dia de hoje.
                    $hoje = $data === date("Y-m-d");
                    // Busca a configuração de horário deste dia da semana.
                    $config_dia = $horarios_dia[$dia_sem] ?? ["trabalha" => 0];
                    $trabalha = (int)($config_dia["trabalha"] ?? 0);
                    // Verifica se existe algum bloqueio nesta data.
                    $tem_bloqueio = isset($bloqueios[$data]);

                    // Define qual cor e estilo o dia terá no calendário.
                    $classe = "";
                    if ($tem_bloqueio) $classe = "dia-bloqueado";      // cor de bloqueado
                    elseif ($trabalha === 1) $classe = "dia-trabalho";  // cor de dia útil
                    if ($hoje) $classe .= " dia-hoje";                   // destaque para hoje
                ?>

                <!-- Caixa que representa um dia no calendário -->
                <div class="calendario-dia <?= trim($classe) ?>">
                    <!-- Número do dia -->
                    <div class="calendario-dia-numero"><?= $dia ?></div>

                    <!-- Se o dia estiver bloqueado, mostra etiqueta vermelha -->
                    <?php if ($tem_bloqueio): ?>
                        <span class="etiqueta-bloqueado">Bloqueado</span>

                    <!-- Se for dia de trabalho, mostra o horário de atendimento -->
                    <?php elseif ($trabalha === 1): ?>
                        <small class="horario-dia">
                            <?= substr($config_dia["horario_inicio"] ?? "08:00",0,5) ?> -
                            <?= substr($config_dia["ultimo_horario"] ?? "19:00",0,5) ?>
                        </small>
                    <?php endif; ?>

                    <!-- Se houver agendamentos marcados neste dia, lista todos -->
                    <?php if (isset($agendamentos_por_dia[$data])): ?>
                        <?php foreach ($agendamentos_por_dia[$data] as $ag):
                            // Define a cor do agendamento conforme seu status.
                            $st = "etiqueta-pendente";
                            if (($ag["status"] ?? "") === "concluido") $st = "etiqueta-concluido";
                            elseif (($ag["status"] ?? "") === "cancelado") $st = "etiqueta-cancelado";
                            elseif (($ag["status"] ?? "") === "alterado") $st = "etiqueta-alterado";
                        ?>
                        <!-- Caixinha com dados do agendamento -->
                        <div class="agendamento-item">
                            <strong><?= substr($ag["horario"],0,5) ?></strong>
                            <?= htmlspecialchars(mb_substr($ag["nome_cliente"] ?? "Cliente",0,12)) ?>
                            <!-- Botão de cancelar só aparece se o agendamento ainda não foi cancelado -->
                            <?php if(($ag["status"] ?? "") !== "cancelado"): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Cancelar este agendamento?')">
                                    <input type="hidden" name="acao" value="cancelar_agendamento">
                                    <input type="hidden" name="agendamento_id" value="<?= $ag["id"] ?>">
                                    <button type="submit" class="btn-cancelar">✖</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <!-- ======================================================================
         ABA 2 — CONFIGURAÇÃO DE HORÁRIOS PADRÃO POR DIA DA SEMANA
         ====================================================================== -->
    <div class="aba-conteudo <?= $aba==='horarios'?'visivel':'' ?>">
        <div class="form-horarios">
            <h3>Meus Horários de Atendimento</h3>
            <p>Configure os dias da semana que você atende (padrão). Para atender um dia excepcional ou folgar apenas um dia, use as abas ao lado.</p>

            <!-- Formulário que envia os horários configurados para o sistema -->
            <form method="POST" action="?mes=<?= $mes ?>&ano=<?= $ano ?>&aba=horarios">
                <input type="hidden" name="acao" value="salvar_horarios">
                <?php
                $nomes_dias = ["Domingo", "Segunda-feira", "Terça-feira", "Quarta-feira", "Quinta-feira", "Sexta-feira", "Sábado"];
                foreach ($nomes_dias as $num => $nome):
                    $h = $horarios_dia[$num];
                    $chk = ((int)($h["trabalha"] ?? 0) === 1) ? "checked" : "";
                ?>
                <!-- Linha de configuração de cada dia da semana -->
                <div class="linha-dia">
                    <strong><?= $nome ?>:</strong>
                    <label><input type="checkbox" name="trabalha[<?= $num ?>]" value="1" <?= $chk ?>> Trabalha</label>
                    <span>Entrada:</span><input type="time" name="horario_inicio[<?= $num ?>]" value="<?= htmlspecialchars($h["horario_inicio"]) ?>">
                    <span>Último atend.:</span><input type="time" name="ultimo_horario[<?= $num ?>]" value="<?= htmlspecialchars($h["ultimo_horario"]) ?>">
                    <span>Almoço:</span><input type="time" name="inicio_almoco[<?= $num ?>]" value="<?= htmlspecialchars($h["inicio_almoco"]) ?>">
                    <span>às</span><input type="time" name="fim_almoco[<?= $num ?>]" value="<?= htmlspecialchars($h["fim_almoco"]) ?>">
                </div>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-primario">Salvar Horários Padrão</button>
            </form>
        </div>
    </div>

    <!-- ======================================================================
         ABA 3 — REGISTRO DE FOLGAS E BLOQUEIOS PONTUAIS
         ====================================================================== -->
    <div class="aba-conteudo <?= $aba==='bloqueios'?'visivel':'' ?>">
        <div class="form-bloquear">
            <h3>Bloquear Dia ou Período Específico</h3>
            <p>Bloqueia apenas ESTA data — não afeta outros dias da semana. Ideal para folgas, feriados ou compromissos únicos.</p>

            <!-- Formulário para registrar um novo bloqueio -->
            <form method="POST" action="?mes=<?= $mes ?>&ano=<?= $ano ?>&aba=bloqueios">
                <input type="hidden" name="acao" value="bloquear_dia">
                <div class="form-grid">
                    <div class="form-grupo">
                        <label>Data para bloquear *</label>
                        <input type="date" name="data_bloqueio" required>
                    </div>
                    <div class="form-grupo">
                        <label>Tipo de Bloqueio</label>
                        <select name="tipo_bloqueio" id="tipo_bloqueio" onchange="alternarTipoBloqueio(this.value)">
                            <option value="dia_inteiro">Dia Inteiro</option>
                            <option value="horario">Apenas um Horário</option>
                        </select>
                    </div>
                    <!-- Campos de horário que aparecem somente quando bloqueia período parcial -->
                    <div class="form-grupo" id="campos_horario" style="display:none;">
                        <label>Horário de:</label>
                        <input type="time" name="horario_inicio_bloqueio">
                        <label>até:</label>
                        <input type="time" name="horario_fim_bloqueio">
                    </div>
                    <div class="form-grupo">
                        <label>Motivo</label>
                        <input type="text" name="motivo" value="Folga">
                    </div>
                </div>
                <button type="submit" class="btn btn-perigo" style="margin-top:15px;">Bloquear Este Dia/Período</button>
            </form>
        </div>

        <!-- Lista de bloqueios já registrados neste mês -->
        <?php if (!empty($bloqueios)): ?>
        <div class="form-horarios" style="margin-top:20px;">
            <h3>Bloqueios deste Mês</h3>
            <table>
                <thead><tr><th>Data</th><th>Período</th><th>Motivo</th><th>Ação</th></tr></thead>
                <tbody>
                    <?php foreach ($bloqueios as $data_bloq => $lista): foreach ($lista as $b):
                        $in = $b["horario_inicio"] ?? "00:00";
                        $fi = $b["horario_fim"] ?? "23:59";
                        $periodo = ($in === "00:00" && $fi === "23:59") ? "Dia inteiro" : "$in às $fi";
                    ?>
                    <tr>
                        <td><?= date("d/m/Y", strtotime($data_bloq)) ?></td>
                        <td><?= $periodo ?></td>
                        <td><?= htmlspecialchars($b["motivo"]) ?></td>
                        <td>
                            <!-- Botão para excluir bloqueio com confirmação antes de apagar -->
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="acao" value="excluir_bloqueio">
                                <input type="hidden" name="bloqueio_id" value="<?= $b["id"] ?>">
                                <button type="submit" class="btn-cancelar" onclick="return confirm('Excluir bloqueio?')">🗑️</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ======================================================================
         ABA 4 — LIBERAR PERÍODOS PARA RECEBIMENTO DE AGENDAMENTOS
         ====================================================================== -->
    <div class="aba-conteudo <?= $aba==='liberar'?'visivel':'' ?>">
        <div class="form-horarios">
            <h3>Liberar Agendamentos</h3>
            <p>Por padrão, <strong>novos meses NÃO ficam disponíveis</strong> para agendamento. Libere quando quiser receber marcações.</p>

            <!-- Formulário para liberar um período -->
            <form method="POST" action="?mes=<?= $mes ?>&ano=<?= $ano ?>&aba=liberar">
                <input type="hidden" name="acao" value="liberar_agenda">
                <div class="form-grid">
                    <div class="form-grupo">
                        <label>Tipo de Liberação</label>
                        <select name="tipo_liberacao" id="tipo_lib" onchange="alternarLib(this.value)">
                            <option value="mes">Mês Inteiro</option>
                            <option value="semana">Dias Específicos</option>
                        </select>
                    </div>
                    <!-- Seleção de mês que aparece quando libera mês inteiro -->
                    <div class="form-grupo" id="campo_mes">
                        <label>Mês para liberar</label>
                        <select name="mes_liberar">
                            <?php for($m=1; $m<=12; $m++): $mm = str_pad($m,2,"0"); ?>
                            <option value="<?= $mm ?>" <?= $mm === date("m") ? "selected" : "" ?>>
                                <?= $nomes_meses[$mm] ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                        <select name="ano_liberar">
                            <?php for($y=date("Y"); $y<=date("Y")+1; $y++): ?>
                            <option value="<?= $y ?>"><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <!-- Seleção de datas que aparece quando libera dias separados -->
                    <div class="form-grupo linha-completa" id="campo_dias" style="display:none;">
                        <label>Selecione os dias para liberar:</label>
                        <input type="date" name="dias_liberar[]" style="padding:8px; border-radius:6px; border:1px solid #ccc; width:100%;">
                        <small>Digite uma data por vez</small>
                    </div>
                </div>
                <button type="submit" class="btn btn-primario" style="margin-top:15px;">Liberar Agendamentos</button>
            </form>
        </div>

        <!-- Lista de liberações já registradas -->
        <?php if (!empty($liberacoes)): ?>
        <div class="form-horarios" style="margin-top:20px;">
            <h3>Liberações Ativas</h3>
            <table>
                <thead><tr><th>Tipo</th><th>Período</th><th>De</th><th>Até</th></tr></thead>
                <tbody>
                    <?php foreach ($liberacoes as $l):
                        $texto_tipo = "Dia";
                        if ($l["tipo"] === "mes") $texto_tipo = "Mês";
                        elseif ($l["tipo"] === "semana") $texto_tipo = "Dias";
                    ?>
                    <tr>
                        <td><?= $texto_tipo ?></td>
                        <td><?= htmlspecialchars($l["valor"]) ?></td>
                        <td><?= date("d/m/Y", strtotime($l["data_inicio"])) ?></td>
                        <td><?= date("d/m/Y", strtotime($l["data_fim"])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</main>

<!-- ==========================================================================
     CÓDIGOS JAVASCRIPT — ALTERNAM CAMPOS CONFORME ESCOLHA DO USUÁRIO
     ========================================================================== -->
<script>
    // Mostra ou esconde os campos de horário no formulário de bloqueio
    // conforme a profissional escolhe bloquear dia inteiro ou só um horário.
    function alternarTipoBloqueio(tipo) {
        document.getElementById('campos_horario').style.display = tipo === 'horario' ? 'block' : 'none';
    }

    // Mostra o campo de mês ou o campo de datas conforme o tipo de liberação escolhido.
    function alternarLib(tipo) {
        document.getElementById('campo_mes').style.display = tipo === 'mes' ? 'block' : 'none';
        document.getElementById('campo_dias').style.display = tipo === 'semana' ? 'block' : 'none';
    }
</script>
</body>
</html>