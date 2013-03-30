<?php
/*-------------------------------------------------------------------------
 *
 * pshack.php
 *		Biblioteca para acessar a plataforma PagSeguro emulando um navegador
 * 
 * PS: caso queira alguma função que não esteja aqui, mas exista no site, ou 
 * tenha encontrado algum bug ou possua dúvida ou tenha sugestões, favor criar 
 * um tícket em:
 *  https://github.com/loureirorg/php-pshack/issues
 *
 *
 * Copyleft 2013 - Public Domain
 * Original Author: Daniel Loureiro
 *
 * version 1.0a @ 2013-03-30
 *
 * https://github.com/loureirorg/php-pshack
 *
 *-------------------------------------------------------------------------
 *
 * FUNÇÕES:
 *   pshack\config: configura usuário e senha
 *   pshack\extrato_financeiro($inicio, $termino): extrai um relatório de 
 * movimentação financeira (a receber, bloqueado, disponível)
 *   pshack\detalhes($transaction_id): detalhes de uma transação
 *   pshack\estorno($transaction_id): estorna uma compra
 *
 *-------------------------------------------------------------------------
 *
 * EXEMPLO DE USO:
 * <?php
 *    include "pshack.php";
 *
 *    pshack\config("user", "meu@email.com");
 *    pshack\config("pass", "minha_senha_secreta");
 *    print_r(pshack\extrato_financeiro("20130326", "20130330"));
 * ?>
 *
 *-------------------------------------------------------------------------
 */
namespace pshack;


// configurações da biblioteca:
function config()
{
	global $__ps_config;
	if (!isset($__ps_config)) {
		$__ps_config = array();
	}
	$config = (func_num_args() == 1)? func_get_arg(0): array(func_get_arg(0) => func_get_arg(1));
	$__ps_config = array_merge($__ps_config, $config);
}


// função para entrar no pagseguro. usada internamente pelas funções públicas,
// não há necessidade de ser chamada diretamente.
function login()
{
	// destruímos a sessão anterior (do pagseguro):
	if (session_id() == "") {
		session_start();
	}
	unset($_SESSION["__ps_http_read_cookie"]);
	
	// na página inicial, pega form:
	$html = http_read("https://pagseguro.uol.com.br/");
	if (!preg_match("#<form .* action=[\"']([^ \"']*)[^>]*(.*)</form>#s", $html, $matches)) {
		return	"Form de login não encontrado";
	}
	$url = "https://pagseguro.uol.com.br/". $matches[1];
	$str_campos = $matches[2];
	
	// campos do form:
	if (!preg_match_all("#<input.*name=[\"']([^\"']*)[\"'] *(value=[\"']([^\"']*)[\"'])?#", $str_campos, $matches)) {
		return	"Campos de login não encontrados";
	}
	$form = array_combine($matches[1], $matches[3]);
	
	// usuário e senha são os campos do tipo text e password respectivamente:
	global $__ps_config;
	$field_user = preg_match("#<input.* type=[\"']text[\"'] name=[\"']([^\"']*)[\"']#", $str_campos, $matches)? $matches[1]: "user";
	$field_pass = preg_match("#<input.* type=[\"']password[\"'] name=[\"']([^\"']*)[\"']#", $str_campos, $matches)? $matches[1]: "pass";
	$form[$field_user] = $__ps_config["user"];
	$form[$field_pass] = $__ps_config["pass"];
	$html = http_read($url, http_build_query($form));
	
	// testa se conseguiu entrar:
	return	preg_match("#Extrato financeiro#", $html, $matches);
}


// retorna um id interno da página. usada internamente pelas funções públicas,
// não há necessidade de ser chamada diretamente.
function find_id($transaction_id)
{
	// exemplo de transaction_id: 7C78FDFB90214361B8F266502BEE27B9
	// Normalizamos, retirando caracteres inválidos e passando para minúsculas:
	$transaction_id = preg_replace("/[^0-9A-Z]/", "", strtoupper($transaction_id));
	
	// utilizamos o caché:
	if (apc_exists($transaction_id)) {
		return	unserialize(apc_fetch($transaction_id));
	}
	
	// caso não esteja no caché, descobrimos os dias que não estão no caché e pesquisamos (agrupados por períodos):
	// buscamos de hoje até o menor dia que não esteja em caché (max 120 dias):
	$delta = 0;
	while ($delta <= 120) 
	{
		// acha próximo dia sem caché:
		$termino = date("Ymd", time() - (24 * 60* 60 * $delta));
		while ($delta <= 120) 
		{
			$termino = date("Ymd", time() - (24 * 60* 60 * $delta));
			$index_name = md5($termino. $_SESSION["__ps_user"]);
			if (!apc_exists($index_name)) {
				break;
			}
			$delta++;
		}
		
		// acha próximo dia com caché:
		$inicio = date("Ymd", time() - (24 * 60* 60 * $delta));
		while ($delta <= 120) 
		{
			$inicio = date("Ymd", time() - (24 * 60* 60 * $delta));
			$index_name = md5($inicio. $_SESSION["__ps_user"]);
			if (apc_exists($index_name)) 
			{
				$inicio = date("Ymd", time() - (24 * 60* 60 * ($delta + 1)));
				break;
			}
			$delta++;
		}
		
		// alimenta caché e verifica se já é suficiente:
		extrato_financeiro($inicio, $termino);
		if (apc_exists($transaction_id)) {
			return	unserialize(apc_fetch($transaction_id));
		}
	}
	
	// não achou, buscamos hoje (pois ainda não encerrou, logo cache pode estar desatualizado)
	extrato_financeiro(date("Ymd"), date("Ymd"));
	if (apc_exists($transaction_id)) {
		return	unserialize(apc_fetch($transaction_id));
	}
	
	// não achou nem hoje, retorna que não existe nos últimos 120 dias
	return	false;
}


// pega detalhes de uma transação:
function detalhes($transaction_id)
{
	// exemplo de transaction_id: 7C78FDFB90214361B8F266502BEE27B9
	// Normalizamos, retirando caracteres inválidos e passando para minúsculas:
	$transaction_id = preg_replace("/[^0-9A-Z]/", "", strtoupper($transaction_id));
	
	// converte id de transação para id interno:
	$id = find_id($transaction_id);
	if (!$id) {
		return	"Não achei a transação";
	}
	
	// pega html da página de detalhes:
	$html = http_read("https://pagseguro.uol.com.br/transaction/details.jhtml?id=$id");
	
	// se html não é o esperado tenta logar novamente:
	if (!preg_match("#<div id='codtrans'>(.*?)</div>#s", $html, $matches)) 
	{
		login();
		$html = http_read("https://pagseguro.uol.com.br/transaction/details.jhtml?id=$id");
		
		// se ainda assim não é o esperado, sai fora
		if (!preg_match("#<div id='codtrans'>(.*?)</div>#s", $html, $matches)) {
			return	"Página não está em formato esperado";
		}
	}
	
	// extrai dados:
	$dados = array();
	$itens = array(
		"resumo" => "#<div id='codtrans'>(.*?)</div>#s",
		"comprador" => "#<div id='vbox'>(.*?)</div>#s",
		"carrinho" => '#<table summary="Dados da compra">(.*?)</table>#s',
		"endereco" => "#<div id='end'>(.*?)</div>#s",
		"financeiro" => "#<div id='trans'>(.*?)</div>#s",
		"status" => "#<div id='status'>(.*?)</div>#s",
	);
	foreach ($itens as $nome => $regex) 
	{
		preg_match($regex, $html, $matches);
		$tmp_ = preg_replace("#(</?strong>|</?b>|</?a.*?>|</?font.*?>|</?span.*?>|<!--.*-->)#s", "", $matches[1]); // retira formatação
		$tmp = extract_data($tmp_);
		$dados[$nome] = $tmp;
	}
	
	// normaliza dados antes de retornar:
	// seção "resumo":
	$dados["resumo"] = $dados["resumo"]["dl"]["dd"];
	preg_match("#\(([^\)]*)#", $dados["resumo"][6], $matches); // parcelamento
	$parcelamento = $matches[1];
	$dados["resumo"] = array(
		"status"		=> trim($dados["resumo"][0]),
		"id_transacao"	=> trim($dados["resumo"][1]),
		"referencia"	=> trim($dados["resumo"][2]),
		"valor"			=> str_replace(",", ".", preg_replace("#[^0-9,]#", "", $dados["resumo"][3])) + 0,
		"taxas"			=> str_replace(",", ".", preg_replace("#[^0-9,]#", "", $dados["resumo"][4])) + 0,
		"total"			=> str_replace(",", ".", preg_replace("#[^0-9,]#", "", $dados["resumo"][5])) + 0,
		"meio_pagamento"=> trim(preg_replace("#(\([^\)]*\))#", "", $dados["resumo"][6])),
		"parcelamento"	=> $parcelamento,
	);
	
	// seção "comprador":
	$dados["comprador"] = $dados["comprador"]["dl"]["dd"];
	preg_match("#\(([^\)]*)#", $dados["comprador"][2], $matches); // pontuação
	$pontuacao_percentual = preg_replace("#[^0-9]#", "", $matches[1]) + 0;
	$dados["comprador"] = array(
		"nome"		=> trim($dados["comprador"][0]),
		"email"		=> trim($dados["comprador"][1]),
		"telefone"	=> trim($dados["comprador"][3]),
		"pontuacao"	=> trim(preg_replace("#(\([^\)]*\))#", "", $dados["comprador"][2])) + 0,
		"pontuacao_perc" => $pontuacao_percentual,
	);
	
	// seção "carrinho"
	$resumo = array(
		"frete" => str_replace(",", ".", preg_replace("#[^0-9,]#", "", $dados["carrinho"]["tfoot"]["tr"][0]["td"])) + 0,
		"total" => str_replace(",", ".", preg_replace("#[^0-9,]#", "", $dados["carrinho"]["tfoot"]["tr"][1]["td"])) + 0,
	);
	$itens = $dados["carrinho"]["tbody"]["tr"];
	if (array_key_exists("td", $itens)) {
		$itens = array($itens);
	}
	$norm = array();
	foreach ($itens as $item) // normaliza
	{
		$item = $item["td"];
		$norm[] = array(
			"id"			=> trim($item[0]),
			"produto"		=> trim($item[1]),
			"quantidade"	=> preg_replace("#[^0-9]#", "", $item[2]) + 0,
			"valor"			=> str_replace(",", ".", preg_replace("#[^0-9,]#", "", $item[3])) + 0,
			"total"			=> str_replace(",", ".", preg_replace("#[^0-9,]#", "", $item[4])) + 0,
		);
	}
	$dados["carrinho"] = array(
		"resumo"	=> $resumo,
		"itens"		=> $norm,
	);
	
	// seção "endereço"
	$dados["endereco"] = $dados["endereco"]["dl"]["dd"];
	$dados["endereco"] = array(
		"cep"		=> trim($dados["endereco"][0]),
		"endereco"	=> trim($dados["endereco"][1]),
		"numero"	=> trim($dados["endereco"][2]),
		"bairro"	=> trim($dados["endereco"][3]),
		"cidade"	=> trim($dados["endereco"][4]),
		"estado"	=> trim($dados["endereco"][5]),
	);
	
	// seção "financeiro"
	$dados["financeiro"] = $body = array_map(create_function('$i', 'return $i[td];'), $dados["financeiro"]["table"]["tbody"]["tr"]);
	$norm = array();
	foreach ($dados["financeiro"] as $item)
	{
		$ts = \DateTime::createFromFormat("d/m/Y H:i", $item[0]);
		$norm[] = array(
			"data"		=> $ts? $ts->format("Ymd\THi"): "",
			"descricao"	=> trim($item[1]),
			"valor"		=> str_replace(",", ".", preg_replace("#[^0-9,]#", "", $item[2])) + 0,
			"tipo"		=> trim($item[3]),
		);
	}
	$dados["financeiro"] = $norm;
	
	// seção "status"
	$dados["status"] = $body = array_map(create_function('$i', 'return $i[td];'), $dados["status"]["table"]["tbody"]["tr"]);
	$norm = array();
	foreach ($dados["status"] as $item)
	{
		$ts = \DateTime::createFromFormat("d/m/Y H:i", $item[0]);
		$norm[] = array(
			"data"		=> $ts? $ts->format("Ymd\THi"): "",
			"status"	=> trim($item[1]),
		);
	}
	$dados["status"] = $norm;
	
	// reorganizamos vetores:
	$dados["comprador"] = array_merge($dados["comprador"], $dados["endereco"]);
	unset($dados["endereco"]);
	$dados["resumo"]["frete"] = $dados["carrinho"]["resumo"]["frete"];
	$dados["carrinho"] = $dados["carrinho"]["itens"];
	
	// fim
	return	$dados;
}

// estorna uma compra:
function estorno($transaction_id)
{
	// exemplo de transaction_id: 7C78FDFB90214361B8F266502BEE27B9
	// Normalizamos, retirando caracteres inválidos e passando para minúsculas:
	$transaction_id = preg_replace("/[^0-9A-Z]/", "", strtoupper($transaction_id));
	
	// converte id de transação para id interno:
	$id = find_id($transaction_id);
	if (!$id) {
		return	"Não achei a transação";
	}
	
	// pega html da página de detalhes:
	$html = http_read("https://pagseguro.uol.com.br/transaction/details.jhtml?id=$id");
	
	// se html não é o esperado tenta logar novamente:
	if (!preg_match("#<div id='codtrans'>(.*?)</div>#s", $html, $matches)) 
	{
		login();
		$html = http_read("https://pagseguro.uol.com.br/transaction/details.jhtml?id=$id");
		
		// se ainda assim não é o esperado, sai fora
		if (!preg_match("#<div id='codtrans'>(.*?)</div>#s", $html, $matches)) {
			return	"Página não está no formato esperado";
		}
	}
	
	// pega form de estorno:
	if (!preg_match("#<form id=\"refund\".* action=[\"']([^ \"']*)[^>]*(.*)</form>#s", $html, $matches)) {
		return	"Form de estorno não encontrado";
	}
	$url = "https://pagseguro.uol.com.br/". $matches[1];
	$str_campos = $matches[2];
	
	// campos do form:
	if (!preg_match_all("#<input.*name=[\"']([^\"']*)[\"'] *(value=[\"']([^\"']*)[\"'])?#", $str_campos, $matches)) {
		return	"Campos de estorno não encontrados";
	}
	$form = array_combine($matches[1], $matches[3]);
	
	// faz a chamada de estorno:
	$html = http_read($url, http_build_query($form));
	// return	preg_match("#O pagamento será estornado na próxima fatura do cartão de crédito do cliente.#", $html, $matches);
	
	// fim:
	return	true;
}

// função que simula o acesso ao item "Extrato Financeiro" do site:
// PS: datas no formato iso: AAAAMMDD
function extrato_financeiro($inicio, $termino)
{
	// termino é maior que inicio ou data é inválida, retorna false representando erro:
	if (($ts_termino < $ts_inicio) OR !\DateTime::createFromFormat("Ymd", $termino) OR !\DateTime::createFromFormat("Ymd", $inicio)) {
		return	false;
	}
	
	// chama página e pega o html:
	$form = array(
		"comboPeriod"	=> "120",
		"finalDate"		=> \DateTime::createFromFormat("Ymd", $termino)->format("d/m/Y"),
		"finalDateHid"	=> \DateTime::createFromFormat("Ymd", $termino)->format("d/m/Y"),
		"initialDate"	=> \DateTime::createFromFormat("Ymd", $inicio)->format("d/m/Y"),
		"sendfilter"	=> "Filtrar",
	);
	$html = http_read("https://pagseguro.uol.com.br/statement/period.jhtml", http_build_query($form));
	
	// verificamos se sessão não encerrou e refaz login se necessário
	if (!preg_match('#table.*id="available_extract"([^>]*)>(.*?)</table>#s', $html, $matches))
	{
		login();
		$html = http_read("https://pagseguro.uol.com.br/statement/period.jhtml", http_build_query($form));
		
		// ainda não é o esperado, sai fora
		if (!preg_match('#table.*id="available_extract"([^>]*)>(.*?)</table>#s', $html, $matches)) {
			return	false;
		}
	}
	
	// dados extraídos do html: são 3 tabelas a serem lidas: 
	// disponível (available_extract), a receber (escrow_extract), bloqueado (contest_extract):
	$dados = array();
	$tables = array("disponivel" => "available_extract", "receber" => "escrow_extract", "bloqueado" => "contest_extract");	
	foreach ($tables as $ntable => $table)
	{
		// pega tabela html:
		$ok = preg_match('#table.*id="available_extract"([^>]*)>(.*?)</table>#s', $html, $matches); // pega somente a tabela
		if (!$ok) 
		{
			$dados[$ntable] = array("saldo_anterior" => null, "saldo_final" => null, "listagem" => array());
			continue;
		}
		
		// extrai dados da tabela html:
		$tabela_html = preg_replace("#(<b>|</b>|<a href='|</a>|<font.*?>|</font>| class=\"[^\"]*\"|<span.*?>|</span>)#s", "", $matches[2]); // retira formatação
		$tabela_html = preg_replace("#(' title=\"[^\"]*\">)#s", ";", $tabela_html); // link id
		$tabela = extract_data($tabela_html);

		// cabeçalho e corpo:
		$head = $tabela["thead"]["tr"]["th"];
		if (array_key_exists("td", $tabela["tbody"]["tr"])) {
			$tabela["tbody"]["tr"] = array($tabela["tbody"]["tr"]);// só tem 1 item
		}
		$body = array_map(create_function('$i', 'return $i[td];'), $tabela["tbody"]["tr"]);
		
		// normaliza data (p/ iso), números. Coloca informação normalizada em body:
		foreach ($body as $k => $v)
		{
			$id_chave = explode(";", trim($v[1]));
			preg_match("#id=(.*)#", $id_chave[0], $matches); // extrai o id do link
			$id = $matches[1];
			$ts = \DateTime::createFromFormat("d/m/Y H:i", $v[0]);
			$dia = $ts? $ts->format("Ymd\THi"): "";
			$body[$k] = array(
				$dia,
				$id_chave[0],
				$id_chave[1],
				$id,
				trim($v[2]),
				str_replace(",", ".", str_replace(".", "", $v[3])) + 0,
				str_replace(",", ".", str_replace(".", "", $v[4])) + 0,
			);
		}
		
		// separa dados extraídos e normalizados ($body) em resumos (saldo anterior / saldo_final) e listagem analítica:
		$dados[$ntable] = array(
			"saldo_anterior" => array_shift($body), 
			"saldo_final" => array_pop($body), 
			"listagem" => $body
		);
	}

	// aproveitamos para alimentar cache de ids:
	// agrupamos as listagens das 3 tabelas:
	$lst_itens = array();
	foreach ($dados as $table) {
		$lst_itens = array_merge($lst_itens, $table["listagem"]);
	}
	
	// cada dia é um índice
	$lst_index = array();
	foreach ($lst_itens as $item) 
	{
		$dia = substr($item[0], 0, 8);
		$lst_index[$dia][$item[2]] = $item[3];
	}
	
	// dias que não tiveram movimentação devem ficar em caché também
	$ts = \DateTime::createFromFormat("Ymd", $inicio)->getTimestamp();
	$ts_termino = \DateTime::createFromFormat("Ymd", $termino)->getTimestamp();
	while ($ts <= $ts_termino) 
	{
		$dia = date("Ymd", $ts);
		$ts += 60 * 60 * 24;
		if (!array_key_exists($dia, $lst_index)) {
			$lst_index[$dia] = array();
		}
	}
	
	// armazena cada índice. Considera o usuário logado para evitar conflito de cachés
	foreach ($lst_index as $dia => $vetor)
	{
		$index_name = md5($dia. $_SESSION["__ps_user"]);
		cache_update_index($index_name, $vetor, 0);
	}
	
	// fim: retorna dados das 3 tabelas
	return	$dados;
}


/***
 *** FUNÇÕES AUXILIARES
 ***/
 
function array_combine_($keys, $values)
{
	$result = array();
	foreach ($keys as $i => $k) {
		$result[$k][] = $values[$i];
	}
	array_walk($result, create_function('&$v', '$v = (count($v) == 1)? array_pop($v): $v;'));
	return	$result;
}
 
function extract_data($str)
{
	return
		(is_array($str))?
		array_map(__NAMESPACE__. '\extract_data', $str):
		((!preg_match_all('#<([A-Za-z0-9_]*)[^>]*>(.*?)</\1>#s', $str, $matches))? 
		$str:
		array_map((__NAMESPACE__. '\extract_data'), array_combine_($matches[1], $matches[2])));
}

// faz uma requisição http:
// args: $url[, $post_data]
function http_read()
{
	// args:
	$url = func_get_arg(0);
	$post_data = (func_num_args() == 2)? func_get_arg(1): null;
	
	// headers:
	$headers = array(
		"User-Agent: Mozilla/Curl",
		"Expect: ",
	);
	
	// post:
	if ($post_data != null) 
	{
		$headers[] = "Content-Type: application/x-www-form-urlencoded; charset=utf-8";
		$headers[] = "Content-Length: ". (mb_strlen($post_data, "UTF-8"));
	}

	// cookies:
	if (session_id() == "") {
		session_start();
	}
	if (!array_key_exists("__ps_http_read_cookie", $_SESSION)) {
		$_SESSION["__ps_http_read_cookie"] = array();
	}
	if (count($_SESSION["__ps_http_read_cookie"])) 
	{
		$array = array_map(
			create_function('$k, $v', 'return "$k=$v";'), 
			array_keys($_SESSION["__ps_http_read_cookie"]), 
			array_values($_SESSION["__ps_http_read_cookie"])
		);
		$headers[] = "Cookie: ". implode("; ", $array);
	}
	
	// server comunication:
	global $_http_read_curl_;
	if (!isset($_http_read_curl_)) {
		$_http_read_curl_ = curl_init();
	}
	curl_setopt($_http_read_curl_, CURLOPT_URL, $url);	
	curl_setopt($_http_read_curl_, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($_http_read_curl_, CURLOPT_HEADER, true); 
	if ($post_data != null) 
	{
		curl_setopt($_http_read_curl_, CURLOPT_POST, true);
		curl_setopt($_http_read_curl_, CURLOPT_HTTPGET, false);
		curl_setopt($_http_read_curl_, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($_http_read_curl_, CURLOPT_POSTFIELDS, $post_data);
	}
	else 
	{
		curl_setopt($_http_read_curl_, CURLOPT_POST, false);
		curl_setopt($_http_read_curl_, CURLOPT_HTTPGET, true);
		curl_setopt($_http_read_curl_, CURLOPT_CUSTOMREQUEST, "GET");
	}
	curl_setopt($_http_read_curl_, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($_http_read_curl_, CURLOPT_TIMEOUT, 600);
	curl_setopt($_http_read_curl_, CURLOPT_CONNECTTIMEOUT, 4);
	curl_setopt($_http_read_curl_, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($_http_read_curl_, CURLOPT_FOLLOWLOCATION, false);
	curl_setopt($_http_read_curl_, CURLOPT_AUTOREFERER, true);
	$result = curl_exec($_http_read_curl_);

	// head + body split:
	list($head, $body) = explode("\r\n\r\n", $result, 2);

	// cookies:
	if (preg_match_all('#Set-Cookie: ([^=]*)=([^;]*);.*#', $head, $matches)) {
		$_SESSION["__ps_http_read_cookie"] = array_merge($_SESSION["__ps_http_read_cookie"], array_combine($matches[1], $matches[2]));
	}
	
	// fim: se resposta foi um "Location", retorna página apontada. Se não, converte para utf8 e retorna body
	if (preg_match('#Location: (.*)?#', $head, $matches)) {
		return	http_read($matches[1]);
	}
	
	// encoding:
	if (preg_match('#Content-Type: .*charset=(.*);?#', $head, $matches) AND ($matches[1] != "utf8")) {
		$body = mb_convert_encoding($body, "utf8");
	}
	return	$body;
}

// invalida (exclui) um índice e seu vetor de dados
function cache_invalidate_index($index_name)
{
	// se existe, destrói índice e dados indexados:
	if (apc_exists($index_name))
	{
		$keys = unserialize(apc_fetch($index_name));
		foreach ($keys as $key) {
			apc_delete($key);
		}
		apc_delete($index_name);
	}
}

// salva um índice e seu vetor de dados:
// args: $index_name, $index_data[, ttl]
// exemplo de args I: ("idx_teste", array("a", "b"))
// exemplo de args II: ("idx_teste", array("x" => "a", "y" => "b"))
function cache_update_index()
{
	// args:
	$index_name = func_get_arg(0);
	$index_data = func_get_arg(1);
	$ttl = (func_num_args() >= 3)? func_get_arg(2): 0;

	// se já existe, destrói índice e dados indexados:
	cache_invalidate_index($index_name);
	
	// insere índice e seus dados:
	$keys = array();
	foreach ($index_data as $chave => $item) 
	{
		$value = serialize($item);
		$key = preg_match("#^[0-9]*$#", $chave)? md5($value): $chave; // se índice é uma chave, aproveita ela, senão gera com base no conteúdo
		$keys[] = $key;
		apc_store($key, $value, $ttl);
	}
	apc_store($index_name, serialize($keys), $ttl);
}

// pega o vetor de dados de um índice:
function cache_fetch_index($index_name)
{
	// se obteve sucesso retorna um vetor com os dados, se não, retorna "false":
	if (!apc_exists($index_name)) {
		return	false;
	}
	
	// retorna o vetor com dados:
	$keys = unserialize(apc_fetch($index_name));
	$itens = array();
	foreach ($keys as $key) {
		$itens[] = unserialize(apc_fetch($key));
	}
	return	$itens;
}
?>