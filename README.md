#php-pshack
[![githalytics.com alpha](https://cruel-carlota.pagodabox.com/d550557bc8d2922c15da7d88dfc9882c "githalytics.com")](http://githalytics.com/loureirorg/php-pshack)
Biblioteca para acessar a plataforma PagSeguro emulando um navegador (ps-hack)

##Funções
 * pshack\config: configura usuário e senha
 * pshack\extrato_financeiro($inicio, $termino): extrai um relatório de movimentação financeira (a receber, bloqueado, disponível)
 * pshack\detalhes($transaction_id): detalhes de uma transação
 * pshack\estorno($transaction_id): estorna uma compra

##Exemplo de uso
```php
<?php
  include "pshack.php";

  pshack\config("user", "meu@email.com");
  pshack\config("pass", "minha_senha_secreta");
  pshack\estorno("7C78FDFB90214361-B8F266502BEE27B9");
  print_r(pshack\extrato_financeiro("20130326", "20130330"));
?>
```
