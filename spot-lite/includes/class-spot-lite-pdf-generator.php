<?php
// Conexão com o banco de dados
$conn = new mysqli("localhost", "usuario", "senha", "banco");

if ($conn->connect_error) {
    die("Falha na conexão: " . $conn->connect_error);
}

// ID do relatório que queremos processar
$report_id = 1; // Você pode alterar isso dinamicamente, por exemplo, com $_GET['id']

// Query para buscar os dados do relatório
$sql_report = "
    SELECT r.title, r.general_event_description, p.name AS project_name
    FROM reports r
    JOIN projects p ON r.project_id = p.id
    WHERE r.id = ?
";
$stmt = $conn->prepare($sql_report);
$stmt->bind_param("i", $report_id);
$stmt->execute();
$result = $stmt->get_result();
$report = $result->fetch_assoc();

// Verifique se o relatório existe
if (!$report) {
    die("Relatório não encontrado.");
}

// Query para buscar as fotos do relatório
$sql_photos = "SELECT url FROM photos WHERE report_id = ?";
$stmt = $conn->prepare($sql_photos);
$stmt->bind_param("i", $report_id);
$stmt->execute();
$result = $stmt->get_result();

$photos_html = '';
while ($photo = $result->fetch_assoc()) {
    $photos_html .= '<img src="' . htmlspecialchars($photo['url']) . '" alt="Foto do Relatório" width="200">';
}

// Query para buscar os participantes e suas atividades
$sql_participants = "
    SELECT par.name, TIMESTAMPDIFF(YEAR, par.birth_date, CURDATE()) AS idade, act.description
    FROM participants par
    JOIN activities act ON par.id = act.participant_id
    WHERE act.report_id = ?
";
$stmt = $conn->prepare($sql_participants);
$stmt->bind_param("i", $report_id);
$stmt->execute();
$result = $stmt->get_result();

$participants_html = '';
while ($participant = $result->fetch_assoc()) {
    $participants_html .= '<tr>';
    $participants_html .= '<td class="tb_est">' . htmlspecialchars($participant['name']) . '</td>';
    $participants_html .= '<td class="tb_idd">' . htmlspecialchars($participant['idade']) . '</td>';
    $participants_html .= '<td class="tb_reg">' . htmlspecialchars($participant['description']) . '</td>';
    $participants_html .= '</tr>';
}

// Carregar o template HTML
$template = file_get_contents("template.html");

// Substituir os placeholders no template
$template = str_replace("{{Nome do titulo}}", htmlspecialchars($report['title']), $template);
$template = str_replace("{{Descrição do relatório}}", htmlspecialchars($report['general_event_description']), $template);
$template = str_replace("{{Fotos}}", $photos_html, $template);
$template = str_replace("{{Linhas Participantes}}", $participants_html, $template);

// Exibir o HTML final
echo $template;

// Fechar conexão
$stmt->close();
$conn->close();
?>
