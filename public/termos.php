<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/storefront.php';

$conn      = (new Database())->connect();
$cartCount = sfCartCount();

$currentPage = 'termos';
$pageTitle   = 'Termos de Uso — Basefy';

include __DIR__ . '/../views/partials/header.php';
include __DIR__ . '/../views/partials/storefront_nav.php';
?>

<style>
    .legal-fade { opacity: 0; transform: translateY(20px); animation: legalFade 0.6s ease forwards; }
    @keyframes legalFade { to { opacity: 1; transform: translateY(0); } }
    .legal-delay-1 { animation-delay: 0.05s; }
    .legal-delay-2 { animation-delay: 0.10s; }
    .legal-delay-3 { animation-delay: 0.15s; }

    .legal-sidebar::-webkit-scrollbar { width: 3px; }
    .legal-sidebar::-webkit-scrollbar-thumb { background: rgba(var(--t-accent-rgb),0.3); border-radius: 99px; }

    .legal-section { scroll-margin-top: 100px; }

    .legal-nav-link {
        display: flex; align-items: center; gap: 10px; padding: 8px 14px;
        border-radius: 10px; font-size: 13px; color: #a1a1aa; transition: all 0.2s;
        border-left: 2px solid transparent; cursor: pointer;
    }
    .legal-nav-link:hover { color: #fff; background: rgba(255,255,255,0.04); }
    .legal-nav-link.active { color: var(--t-accent); border-left-color: var(--t-accent); background: rgba(var(--t-accent-rgb),0.06); font-weight: 600; }

    .legal-body h3 { font-size: 1.1rem; font-weight: 700; margin: 2rem 0 0.8rem; padding-bottom: 0.5rem; border-bottom: 1px solid rgba(255,255,255,0.06); }
    .legal-body p { color: #a1a1aa; font-size: 0.9rem; line-height: 1.8; margin-bottom: 0.8rem; }
    .legal-body ul { list-style: none; padding: 0; margin: 0.5rem 0 1rem; }
    .legal-body ul li { position: relative; padding-left: 1.5rem; color: #a1a1aa; font-size: 0.9rem; line-height: 1.8; margin-bottom: 0.35rem; }
    .legal-body ul li::before { content: ''; position: absolute; left: 0; top: 0.65rem; width: 6px; height: 6px; border-radius: 50%; background: var(--t-accent); opacity: 0.6; }

    .light-mode .legal-body h3 { border-bottom-color: rgba(0,0,0,0.08); }
    .light-mode .legal-body p,
    .light-mode .legal-body ul li { color: #3f3f46; }
    .light-mode .legal-nav-link { color: #52525b; }
    .light-mode .legal-nav-link:hover { color: #18181b; background: rgba(0,0,0,0.04); }
    .light-mode .legal-nav-link.active { color: var(--t-accent); background: rgba(var(--t-accent-rgb),0.08); }
</style>

<div class="min-h-screen bg-blackx" x-data="legalNav()">

    <!-- Breadcrumb -->
    <div class="max-w-6xl mx-auto px-4 sm:px-6 pt-6 legal-fade">
        <nav class="flex items-center gap-2 text-sm text-zinc-500">
            <a href="/" class="hover:text-greenx transition-colors">Início</a>
            <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
            <a href="<?= BASE_PATH ?>/central_ajuda" class="hover:text-greenx transition-colors">Central de Ajuda</a>
            <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
            <span class="text-zinc-300">Termos de Uso</span>
        </nav>
    </div>

    <!-- Hero -->
    <section class="max-w-6xl mx-auto px-4 sm:px-6 pt-8 pb-8 text-center legal-fade legal-delay-1">
        <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-greenx/10 border border-greenx/20 text-greenx text-xs font-semibold mb-5">
            <i data-lucide="scale" class="w-3.5 h-3.5"></i> Documento Legal
        </div>
        <h1 class="text-3xl md:text-4xl font-black tracking-tight">Termos de Uso</h1>
        <p class="text-zinc-400 mt-3 text-sm max-w-2xl mx-auto">Leia atentamente os termos e condições que regem a utilização da plataforma Basefy.</p>
        <p class="text-zinc-600 text-xs mt-2">Última atualização: Março de 2026</p>
    </section>

    <!-- Content -->
    <section class="max-w-6xl mx-auto px-4 sm:px-6 pb-20 legal-fade legal-delay-2">
        <div class="flex gap-8">

            <!-- Sidebar Nav (desktop) -->
            <aside class="hidden lg:block w-64 flex-shrink-0">
                <div class="sticky top-24 bg-blackx2 border border-white/[0.06] rounded-2xl p-4 legal-sidebar max-h-[calc(100vh-120px)] overflow-y-auto">
                    <p class="text-[11px] font-bold uppercase tracking-widest text-zinc-600 mb-3 px-3">Seções</p>
                    <nav class="space-y-1">
                        <a @click.prevent="scrollTo('s1')" :class="active==='s1'?'active':''" class="legal-nav-link"><span class="w-5 text-center text-xs opacity-40">1</span> Aceitação dos Termos</a>
                        <a @click.prevent="scrollTo('s2')" :class="active==='s2'?'active':''" class="legal-nav-link"><span class="w-5 text-center text-xs opacity-40">2</span> Ferramenta de Anúncios</a>
                        <a @click.prevent="scrollTo('s3')" :class="active==='s3'?'active':''" class="legal-nav-link"><span class="w-5 text-center text-xs opacity-40">3</span> Gratuidade e Cobrança</a>
                        <a @click.prevent="scrollTo('s4')" :class="active==='s4'?'active':''" class="legal-nav-link"><span class="w-5 text-center text-xs opacity-40">4</span> Utilização da Ferramenta</a>
                        <a @click.prevent="scrollTo('s5')" :class="active==='s5'?'active':''" class="legal-nav-link"><span class="w-5 text-center text-xs opacity-40">5</span> Registro e Dados Pessoais</a>
                        <a @click.prevent="scrollTo('s6')" :class="active==='s6'?'active':''" class="legal-nav-link"><span class="w-5 text-center text-xs opacity-40">6</span> Regras de Conduta</a>
                        <a @click.prevent="scrollTo('s7')" :class="active==='s7'?'active':''" class="legal-nav-link"><span class="w-5 text-center text-xs opacity-40">7</span> Restrições ao Usuário</a>
                        <a @click.prevent="scrollTo('s8')" :class="active==='s8'?'active':''" class="legal-nav-link"><span class="w-5 text-center text-xs opacity-40">8</span> Propriedade Intelectual</a>
                        <a @click.prevent="scrollTo('s9')" :class="active==='s9'?'active':''" class="legal-nav-link"><span class="w-5 text-center text-xs opacity-40">9</span> Denúncia de Abusos</a>
                        <a @click.prevent="scrollTo('s10')" :class="active==='s10'?'active':''" class="legal-nav-link"><span class="w-5 text-center text-xs opacity-40">10</span> Isenção de Garantias</a>
                        <a @click.prevent="scrollTo('s11')" :class="active==='s11'?'active':''" class="legal-nav-link"><span class="w-5 text-center text-xs opacity-40">11</span> Privacidade de Dados</a>
                        <a @click.prevent="scrollTo('s12')" :class="active==='s12'?'active':''" class="legal-nav-link"><span class="w-5 text-center text-xs opacity-40">12</span> Legislação Aplicável</a>
                    </nav>
                </div>
            </aside>

            <!-- Main Content -->
            <div class="flex-1 min-w-0">
                <div class="bg-blackx2 border border-white/[0.06] rounded-2xl p-6 md:p-10 legal-body">

                    <!-- 1 -->
                    <div id="s1" class="legal-section">
                        <h3>1. Aceitação dos Termos e Condições de Uso</h3>
                        <p>O uso da plataforma digital (doravante denominada "Ferramenta") disponibilizada pela Basefy por meio de seu website está sujeito à aceitação e ao cumprimento dos Termos e Condições de Uso descritos a seguir:</p>
                        <ul>
                            <li>É imprescindível a leitura integral dos termos abaixo.</li>
                            <li>O Usuário deve concordar expressamente com os termos apresentados.</li>
                            <li>É necessário fornecer e validar um canal de comunicação ao postar qualquer conteúdo na plataforma.</li>
                            <li>O Usuário deve agir de acordo com as normas e diretrizes estabelecidas pela Basefy e a legislação vigente no Brasil.</li>
                        </ul>
                        <p>Ao utilizar a Ferramenta fornecida pela Basefy TECNOLOGIA DA INFORMAÇÃO LTDA (doravante denominada "Basefy"), você (doravante denominado "Usuário") declara ter lido, compreendido e aceitado os termos, regras e condições aqui apresentados.</p>
                        <p>A plataforma, no momento, oferece suporte exclusivamente a usuários residentes no Brasil que possuam documentos válidos no território nacional. Usuários estrangeiros poderão utilizar a Ferramenta de forma limitada, o que inviabiliza a validação de documentos na plataforma.</p>
                        <p>O uso da Ferramenta é permitido apenas a pessoas com capacidade civil plena ou devidamente representadas. A aceitação dos termos implica na declaração de que o Usuário é capaz, assistido ou representado, conforme as exigências legais.</p>
                        <p>A Basefy reserva-se o direito de, a qualquer momento, proibir, autorizar, modificar, ou alterar a apresentação, categorização, organização, ordenação, composição, configuração e disponibilização da Ferramenta, conforme sua discricionariedade.</p>
                    </div>

                    <!-- 2 -->
                    <div id="s2" class="legal-section">
                        <h3>2. Ferramenta de Anúncios</h3>
                        <p>A Basefy oferece ao Usuário uma ferramenta que permite a publicação de anúncios no Site, que permanecerá hospedado nos servidores da Basefy. Sob nenhuma circunstância, a Basefy representa os anúncios de terceiros, reservando-se o direito de remover qualquer conteúdo que julgue ofensivo ou prejudicial à boa conduta do site, aos usuários ou aos produtos oferecidos.</p>
                        <p>Embora assegure o direito constitucional de defesa ao anunciante, a Basefy se resguarda no direito de remover imediatamente qualquer conteúdo nocivo, uma vez que sua permanência pode causar prejuízos a terceiros de boa fé.</p>
                        <p>A Basefy também se reserva o direito de desativar e banir contas de usuários, a seu exclusivo critério e mediante análise, para garantir a segurança e integridade da plataforma.</p>
                        <p>Além disso, a Basefy poderá, de forma unilateral, encerrar a prestação de serviços para usuários com baixo índice de reputação e/ou alta incidência de intervenções. Nessas situações, a plataforma poderá reter o saldo do usuário para garantir o ressarcimento de possíveis prejuízos causados a terceiros lesados.</p>
                        <p>É expressamente proibido inserir nos anúncios publicados na ferramenta fotos, vídeos ou links que divulguem contatos pessoais ou sites externos, informações que identifiquem diretamente o produto, como nicks em jogos e plataformas, solicitações de pagamentos externos, ferramentas ilegais como hacks, cheaters, aimbots, scripts ou similares, conteúdo adulto, e cursos pirateados ou qualquer conteúdo sem autoria legítima do vendedor.</p>
                        <p>Além disso, é proibida a comercialização de contas cujo identificador, de qualquer natureza, esteja registrado na ferramenta de Verificação de Contas, o que acarretará na suspensão do uso da plataforma.</p>
                    </div>

                    <!-- 3 -->
                    <div id="s3" class="legal-section">
                        <h3>3. Gratuidade e Cobrança</h3>
                        <p>A plataforma é oferecida ao Usuário de forma gratuita para consulta e navegação de suas páginas, categorias e anúncios.</p>
                        <p>A Basefy oferece planos específicos para vendedores, que incluem taxas aplicáveis conforme o serviço contratado. Para os compradores, a plataforma é disponibilizada gratuitamente, permitindo a navegação, consulta de produtos, categorias e anúncios sem qualquer custo.</p>
                        <p>Para os vendedores, a Basefy oferece planos que incluem taxas incidentes sobre as vendas realizadas na Plataforma. Os planos disponíveis são: <strong>Prata (9,99%)</strong>, <strong>Ouro (11,99%)</strong> e <strong>Diamante (12,99%)</strong>. Ao selecionar um dos planos, o vendedor concorda com as taxas aplicáveis sobre suas vendas e reconhece que estas podem ser alteradas pela Basefy a qualquer momento, mediante aviso prévio.</p>
                        <p>Além disso, a Basefy pode oferecer aos compradores benefícios adicionais, como descontos, promoções ou outras vantagens durante o processo de compra. Esses adicionais estão sujeitos à política da Plataforma, podendo variar conforme as condições estabelecidas, e não são garantidos.</p>
                        <p>Para compras de valor inferior a R$ 25,00 (vinte e cinco reais), será aplicada uma <strong>Taxa Operacional</strong>, a ser paga no momento da transação. Essa taxa cobre os custos de processamento do pagamento e demais operações envolvidas na intermediação da compra como gateways de pagamento parceiros. Devido à natureza transacional para o método de pagamento via Criptomoeda e Boleto poderão ser acrescidas taxas adicionais.</p>
                        <p>Para compras de valor igual ou superior a R$ 25,00 (vinte e cinco reais), não será cobrada a Taxa Operacional.</p>
                        <p>A Taxa Operacional não é reembolsável em nenhuma circunstância. Mesmo que o pedido seja posteriormente cancelado ou reembolsado, essa taxa permanecerá retida, pois a intermediação do pagamento já terá sido concluída no momento da compra.</p>
                        <p>Ao realizar uma compra na plataforma Basefy, o usuário declara estar ciente e de acordo com a aplicação da Taxa Operacional conforme as regras estabelecidas nesta cláusula. A Basefy reserva-se o direito de modificar, ajustar ou atualizar as taxas operacionais a qualquer momento, sem necessidade de aviso prévio. O uso contínuo dos serviços após qualquer alteração implica na aceitação dos novos valores.</p>
                        <p>Os cálculos financeiros da plataforma podem sofrer arredondamentos automáticos de centavos e porcentagens, em razão da natureza monetária dos valores. Tais ajustes podem gerar pequenas variações nos totais exibidos, sem alterar as taxas contratadas nem representar cobranças adicionais.</p>
                    </div>

                    <!-- 4 -->
                    <div id="s4" class="legal-section">
                        <h3>4. Utilização da Ferramenta</h3>
                        <p>O Usuário reconhece que é responsável por quaisquer informações falsas que possam ser prestadas para a utilização da ferramenta, bem como por qualquer comentário ou conteúdo inserido pelo mesmo no Site.</p>
                        <p>Cada pessoa poderá possuir apenas um usuário no site (um CPF), a criação de múltiplos usuários está expressamente proibida, mesmo que utilizando dados de terceiros. Caso o usuário seja banido e crie outra conta, estará infringindo nossas regras e será banido permanentemente.</p>
                        <p>A conta da Basefy é de uso pessoal, intransferível e não devem ser repassadas a terceiros. É proibida sua comercialização. Isso inclui também qualquer valor monetário e bônus.</p>
                        <p>O pagamento feito pelo comprador pode acabar resultando em um reversal, uma retenção de fundos pela operadora de cartão de crédito ou gateway. O ambiente da internet é propício a fraudes e neste caso o pagamento será descontado do saldo do vendedor.</p>
                        <p>A Basefy se reserva ao direito de analisar a propriedade de qualquer fundo, produto ou disputa gerada pela Ferramenta de vendas, podendo colocar em espera, prender ou até mesmo retirar os fundos recebidos e/ou depositados pelo comerciante e/ou usuário. Em caso de prejuízo causado pelo usuário a qualquer outra pessoa ou à Basefy, ao utilizar o site, esta poderá reter qualquer saldo ou valor disponível no site para que os prejuízos causados sejam compensados.</p>
                        <p>Reclamações, disputas ou contestações em que não seja possível constatar o causador do problema e que não tenham solução após 180 dias, terão os fundos retidos.</p>
                        <p>As formas de saque oferecidas são os únicos métodos para realizar a retirada de fundos do site. Nenhuma outra forma que não seja oferecida no menu poderá ser requisitada pelo comerciante e/ou usuário.</p>
                        <p>É dever do usuário realizar a verificação de sua conta, de forma completa, para utilizar ou retirar seu saldo disponível no site. Isso inclui o envio de documentos pessoais requisitados válidos, verdadeiros e atualizados.</p>
                        <p>No caso de eventual banimento de contas da plataforma, por qualquer motivo que seja, a empresa reserva-se o direito de suspender temporariamente o acesso aos saldos das contas banidas, a fim de realizar uma análise minuciosa da origem dos saldos. Esta análise considerará a procedência dos produtos/serviços comercializados, o risco das transações realizadas, a existência de usuários e/ou contrapartes lesadas e quaisquer outros motivos que possam afetar a segurança da plataforma. O saldo congelado poderá ainda ser subtraído a fim de resguardar reembolsos à outras partes envolvidas. A Basefy se compromete a realizar a análise o mais rapidamente possível, porém, caso necessário, a análise poderá ser estendida para até 180 dias.</p>
                        <p>Após a realização da análise completa e a constatação de que não há riscos à segurança da plataforma e à terceiros, o saldo da conta banida será desbloqueado e disponibilizado ao usuário. A empresa prontamente irá informar o usuário sobre o resultado da análise e o prazo estimado para a liberação dos saldos, assim que a análise for concluída. Caso sejam identificados problemas durante a análise e o período estimado de liberação, a empresa poderá tomar as medidas necessárias para garantir a segurança da plataforma, o que pode afetar novamente o prazo de liberação dos saldos.</p>
                        <p>Não será permitido realizar negociações de troca, permuta ou câmbio entre anúncios. Sujeito ao banimento da plataforma.</p>
                        <p>O usuário reconhece previamente as tarifas praticadas na Plataforma, bem como os prazos de liberação do dinheiro proveniente de vendas.</p>
                        <p>Não há nenhum plano que acelere o prazo de liberação na plataforma, sendo este igual para todos os usuários.</p>
                        <p>Uma vez cadastrada, a conta do usuário não poderá ser excluída de nosso banco de dados em caso de atividades (compras e vendas), isso garante a segurança de nossa Plataforma. A Basefy não é obrigada a excluir os dados de usuários caso exista alguma base legal que justifique a manutenção deles. O usuário poderá solicitar a desativação no menu "Meus Dados", ao final da página.</p>
                        <p>Caso não haja nenhuma atividade na conta do usuário, o mesmo poderá solicitar a exclusão da conta no menu "Meus Dados", ao final da página.</p>
                        <p>É expressamente proibido a criação de contas de usuário que contenham ou façam referência a Basefy. Não é permitida a utilização da palavra "basefy" em qualquer nome de usuário no site. A mesma regra deve ser respeitada para a criação de anúncios. Ao desrespeitar esta regra, o usuário pode ter seu nome modificado sem aviso prévio e em último caso, desativação da plataforma.</p>
                        <p>É expressamente proibido anunciar produtos diferentes em um mesmo anúncio ao longo do tempo (exceto anúncio caracterizado como dinâmico), a fim de aproveitar qualquer avaliação ou posição de classificação obtido pelo produto/serviço anunciado anteriormente.</p>
                        <p>A Basefy reserva-se o direito de, a qualquer momento, realizar verificações ou solicitar atualizações cadastrais de usuários, incluindo aqueles já cadastrados ou previamente verificados, conforme seu exclusivo critério e necessidade.</p>
                        <p>As contas dos usuários estão sujeitas a verificações periódicas, realizadas de forma automatizada, aleatória ou manual, com o objetivo de assegurar a segurança e a integridade da plataforma.</p>
                        <p>Caso o usuário estiver com alguma mediação em aberto, a conta será classificada como Irregular. Contas Irregulares não poderão fazer a retirada até que as mediações em aberto sejam resolvidas.</p>
                        <p>Em caso de abertura de mediação entre o comprador e vendedor, a Basefy irá intermediar a conversa para aconselhar, auxiliar e ajudar os usuários e chegar a um ponto comum e/ou aplicar as diretrizes do site, respeitando os Termos de Uso e políticas internas. Em último caso, os usuários concedem a decisão da mediação — mediante informações, dados e provas coletados no chat da plataforma — na responsabilidade de um moderador da Basefy, para um reembolso total ou parcial.</p>
                        <p>Em caso de mediação, o moderador poderá solicitar qualquer informação, dados e provas concretas para compradores e vendedores — a seu exclusivo critério — para auxiliá-lo com o andamento da intervenção vigente.</p>
                        <p>Caso a conta do usuário seja banida, a mesma perderá qualquer saldo, acesso, permissão, suporte, contato e/ou função à plataforma, não sendo possível a reversão de qualquer meio anteriormente citado.</p>
                        <p>É estritamente proibido o compartilhamento de informações e comunicações de contato externo à plataforma, incluindo redes sociais (Instagram, Facebook, etc.), e-mails e aplicativos de mensagens (Discord e semelhantes), em anúncios, campos de perguntas e páginas de pedidos.</p>
                        <p>A Basefy não endossa e não estimula qualquer tipo de contas adquiridas por meio ilícito (crackeadas, obtidas por força bruta e semelhantes), tanto que contas NFA não são permitidas na plataforma.</p>
                        <p>O acesso ao site deve ser feito usando o IP real do local de acesso. O uso de TOR, proxies, VPNs e/ou semelhantes não são permitidos.</p>
                        <p>É expressamente proibido o uso de navegadores antidetect e ferramentas similares, tais como AdsPower, Multilogin, GoLogin, Dolphin Anty, Incogniton, Kameleo, VMLogin, OctoBrowser, SessionBox, entre outros softwares cujo objetivo seja simular múltiplas identidades digitais, manipular fingerprints, ou burlar sistemas de autenticação, rastreamento e segurança da plataforma.</p>
                        <p>A Basefy poderá suspender ou banir qualquer conta que apresente acessos de múltiplas geolocalizações diferentes.</p>
                        <p>O usuário concorda que NÃO irá fazer uso de qualquer tipo de aplicativo, extensão ou ferramenta não oficial da Basefy, incluindo, mas não se limitando a, modificadores de API, extensões de navegador, ou bots automatizados. O uso de qualquer ferramenta não autorizada, assim que identificada pelo sistema, será penalizada — sem aviso prévio — com suspensão ou banimento permanente da conta.</p>
                        <p>Qualquer tentativa ou envolvimento em atividades que a empresa considere prejudiciais à plataforma, aos usuários ou a terceiros poderá resultar em penalizações.</p>
                        <p>Pagamentos e recargas devem ser realizados exclusivamente por contas em nome do titular da conta Basefy. O uso recorrente de contas de terceiros pode levar à suspensão ou banimento do usuário, visando garantir a segurança das transações.</p>
                        <p>É expressamente proibido utilizar recargas realizadas na Basefy para a venda de produtos ou serviços a terceiros. Caso seja identificado o uso indevido da plataforma para fins comerciais, o usuário poderá ser suspenso ou banido, e as transações poderão ser invalidadas.</p>
                        <p>A Basefy prioriza a segurança e a integridade na verificação de identidade. Para minimizar riscos de fraudes e falsificações, não aceitamos documentos digitais em nossos processos. A apresentação da versão original de documentos físicos é obrigatória, incluindo RG, CNH, Carteira de Trabalho e Passaporte.</p>
                    </div>

                    <!-- 5 -->
                    <div id="s5" class="legal-section">
                        <h3>5. Registro e Dados Pessoais</h3>
                        <p>É dever do Usuário manter atualizados os dados pessoais fornecidos quando da utilização da Ferramenta. A Empresa pode cancelar qualquer registro do Usuário, a qualquer momento e sem prévio aviso, assim que tiver conhecimento, e a seu exclusivo critério, se o Usuário descumprir, intencionalmente ou não, estes Termos e Condições de Uso, ou violar leis e regulamentos federais, estaduais e/ou municipais, ou violar os princípios legais, a moral e os bons costumes. Os Usuários que tiverem seus registros cancelados não poderão mais utilizar a Ferramenta, após apresentação da defesa.</p>
                        <p>O usuário ao aceitar os termos dos serviços prestados está ciente e de acordo com todas as cláusulas do presente contrato. Em situação especial, caso o mesmo se utilize de qualquer meio para reaver o pagamento efetuado, seja chargeback, disputas, sustar o pagamento ou qualquer outro meio que impossibilite o crédito, bem como recuperação de contas, itens e produtos digitais, o mesmo estará sujeito a processos tanto civis quanto penais, sendo que tais atitudes qualificam fraude e estelionato, constituindo-se ambas práticas criminais.</p>
                        <p>Por se tratar de uma plataforma séria e para resguardar a segurança de seus usuários, bem como garantir que a lei aplicável seja cumprida, a Basefy se reserva ao direito de execução do presente contrato judicialmente nos casos citados anteriormente, utilizando todos os dados pessoais armazenados na Plataforma e também fica autorizado a divulgação dos dados do usuário transgressor para terceiros.</p>
                        <p>O usuário abre mão de qualquer tipo contestação direto com o banco/meio de pagamento, como chargeback, disputas, sustar o pagamento ou qualquer outro meio que impossibilite o crédito. Uma vez que a plataforma apenas oferece o serviço de intermediação de pagamento. Qualquer contestação referente à compra deve ser resolvida diretamente com o vendedor.</p>
                        <p>A Basefy poderá solicitar, ao seu próprio critério interno, dados adicionais para que o titular da conta possa comprovar sua identidade e seus dados complementares.</p>
                        <p>Conforme aviso na etapa de preenchimento, uma vez enviados, os dados pessoais (Nome Completo, CPF e Data de Nascimento) não poderão ser modificados ou transferidos, sendo eles atrelados à conta de forma permanente.</p>
                        <p>Conforme aviso na etapa de preenchimento, uma vez enviado, o Endereço não poderá ser modificado sem a ajuda de um moderador. Será necessário a abertura de um ticket solicitando a alteração e esta estará sujeita a análise interna para ser aprovada.</p>
                        <p>Embora a identidade (ou qualquer documento de identificação) não tenha prazo de validade por lei, a Basefy pode negar identidades emitidas há mais de 10 anos, por medida de segurança contra fraudes. Havendo dúvida quanto ao estado de conservação, quanto à fotografia ou quanto à data de emissão da identidade, a empresa deve ser consultada com antecedência, evitando transtornos. Se, pelo decurso do tempo a foto do documento não conseguir expressar a identificação da pessoa que o porta, não poderá ser aceito, por perder a sua finalidade.</p>
                    </div>

                    <!-- 6 -->
                    <div id="s6" class="legal-section">
                        <h3>6. Regras de Conduta do Usuário</h3>
                        <p><strong>O USUÁRIO SE COMPROMETE A NÃO UTILIZAR A FERRAMENTA PARA A PUBLICAÇÃO, CRIAÇÃO, ARMAZENAMENTO E/OU DIVULGAÇÃO DE:</strong></p>
                        <ul>
                            <li>Conteúdo abusivo, como textos, fotos e/ou vídeos que tenham caráter difamatório, discriminatório, obsceno, ofensivo, ameaçador, abusivo, vexatório, prejudicial, que contenha expressões de ódio contra pessoas ou grupos, ou que contenha pornografia infantil, pornografia explícita ou violenta, conteúdo que possa ser danoso a menores, que contenha insultos ou ameaças religiosas ou raciais, ou que incentive danos morais e patrimoniais, ou que possa violar qualquer direito de terceiro, notadamente os direitos humanos.</li>
                            <li>Banners publicitários e/ou qualquer tipo de comércio eletrônico que seja considerado ilícito, assim entendidos os que sejam contrários à legislação ou ofendam direitos de terceiros.</li>
                            <li>Qualquer tipo de material protegido por direitos autorais, copyright ou que, por qualquer razão, violem direitos de terceiros.</li>
                            <li>Informações difamatórias e caluniosas ou que sejam contrárias à honra, à intimidade pessoal e familiar ou à imagem das pessoas.</li>
                            <li>Material que incite à violência e à criminalidade, bem como à pirataria de produtos.</li>
                            <li>Conteúdo que provoque danos ao sistema da Basefy.</li>
                        </ul>
                        <p>O usuário do site não irá anunciar o produto e comprar de si mesmo, tendo o anúncio e a conta cancelada. Isso inclui também se auto avaliar por conta de terceiros ou a pedido de terceiros. Será feita uma análise e se constatada fraude de adulteração de reputação e avaliações no site com compras ilegítimas ou forjadas, o usuário será banido permanentemente.</p>
                        <p>Todos os dados de acesso do produto, do serviço, da conta ou item vendido devem ser passados obrigatoriamente através da página do pedido referente. Se os dados não forem passados através do chat a garantia estará automaticamente cancelada.</p>
                        <p>A Basefy possui sistema próprio e automatizado de filtragem de mensagens maliciosas. Caso seja detectado pela equipe ou pelo sistema abuso de meios para burlar a filtragem, a conta será permanentemente banida da plataforma.</p>
                        <p>O usuário fica proibido de fazer propaganda de site ou empresa que não seja a Basefy e não pode fazer anúncio ou qualquer atividade para recrutar outros vendedores do site.</p>
                        <p>O usuário não irá anunciar produtos falsos nem comercializar produtos falsificados.</p>
                        <p>As regras de conduta, Termos de Uso e diretrizes se estendem a todos os canais de atendimento e comunicação da Basefy, como Discord, Email e vice-versa.</p>
                        <p>Será banido por tempo indeterminado o usuário que tentar burlar o sistema de verificação, como o envio de documentos e comprovantes falsos, falsificação de documentos, fraude e forja de qualquer natureza, falsidade ideológica, uso inapropriado de documentos de outrem, uso sem autorização, todos esses supracitados constituindo-se práticas criminais.</p>
                        <p>É proibido aliciar, persuadir e estimular vendedores a trocar seu plano de anúncio para benefício próprio e para obtenção de eventuais descontos.</p>
                        <p>O usuário ao comprar e/ou vender produtos digitais, como contas de jogos e itens virtuais, estão cientes e conhecem os Termos e Condições de Uso da desenvolvedora/proprietária do jogo ou software, e que qualquer quebra, infração ou distrato oriunda do usuário é de sua exclusiva responsabilidade.</p>
                        <p>O usuário ao comprar e/ou vender na plataforma reconhece que as informações relacionadas à negociação de produtos e serviços digitais são confidenciais e não podem ser compartilhadas com terceiros sem autorização prévia da moderação ou outra parte.</p>
                        <p>A Basefy se reserva o direito de penalizar qualquer ação ou comportamento que, a seu critério, seja considerado indevido, impróprio ou prejudicial ao bom funcionamento da plataforma, independentemente de estar previamente listado nos presentes Termos de Uso.</p>
                        <p>Embora a Basefy busque informar claramente os comportamentos proibidos, ela também se reserva o direito de agir em situações não especificadas, com o objetivo de garantir a segurança, integridade e qualidade da experiência de todos os usuários.</p>
                    </div>

                    <!-- 7 -->
                    <div id="s7" class="legal-section">
                        <h3>7. Restrições ao Usuário</h3>
                        <p>O usuário não irá violar qualquer um destes Termos e Condições de Uso; praticar falsidade, assim entendidas a falsidade de informações e a falsidade ideológica; publicar ou transmitir qualquer conteúdo abusivo ou ofensivo nos comentários; replicar ou armazenar conteúdo abusivo nos servidores da Basefy; fazer qualquer coisa ou praticar qualquer ato contrário à boa-fé e aos usos e costumes das comunidades virtuais e que possam ofender qualquer direito de terceiros. Cometer fraude.</p>
                        <p>O usuário não irá violar ou infringir direitos de propriedade intelectual, direitos fiduciários ou contratuais, direitos de privacidade ou publicidade de outros; propagar, distribuir ou transmitir códigos destrutivos, quer tenham ou não causado danos reais.</p>
                        <p>O usuário não irá vender produtos que não são de sua propriedade, produtos de origem duvidosa, produtos falsificados. Isto se aplica também às contas de jogos, que devem ser de total propriedade do vendedor, contendo os dados de criação, email de criação, chave de recuperação (RK), dados pessoais e documentos que venham a ser necessários para comprovar a propriedade da mesma.</p>
                        <p>O usuário não irá reunir dados pessoais ou comerciais para fins comerciais, políticos, de benemerência ou outros, sem o consentimento dos proprietários desses dados; reproduzir, replicar, copiar, alterar, modificar, criar obras derivativas a partir de, vender ou revender qualquer um dos serviços da Basefy.</p>
                        <p>O usuário não usará robôs, "spiders" ou qualquer outro dispositivo, automático ou manual, para monitorar ou copiar qualquer conteúdo do serviço da Basefy; transmitir conteúdo que não pertence ao Usuário; acessar a Ferramenta sem autorização, por meio de práticas de "hacking", "password mining" ou qualquer outro meio fraudulento.</p>
                        <p><strong>O usuário não irá anunciar produtos/serviços proibidos, listados abaixo:</strong></p>
                        <ul>
                            <li>Contas NFA (Non Full Access) ou Inativas.</li>
                            <li>IPTV e Painéis.</li>
                            <li>Produtos piratas.</li>
                            <li>Revenda de Cursos, Ebooks e semelhantes sem autorização ou não serem postadas pelo próprio autor.</li>
                            <li>Revenda de Cursos Online, Acesso de Plataformas de Concursos e semelhantes sem autorização.</li>
                            <li>Hack, Cheats, Aimbots, Bots, Scripts e semelhantes.</li>
                            <li>"Métodos", Apostas e Robôs.</li>
                            <li>Produtos e Serviços duvidosos e maliciosos.</li>
                            <li>Produtos e Serviços ilegais, telas fakes, CC, mix, tokens.</li>
                            <li>Conteúdo Adulto +18.</li>
                            <li>Produtos Físicos.</li>
                            <li>Produtos relacionados à marca Globo e parceiros (Globo, Globoplay, Telecine, Discovery, Premiere, Combate, Claro TV, VIVO TV, Sky, Canais Abertos, Megapix).</li>
                            <li>Produtos relacionados à marca Ifood e parceiros sem autorização de venda e/ou revenda.</li>
                            <li>Produtos relacionados à marca DAZN e parceiros sem autorização de venda e/ou revenda.</li>
                            <li>Produtos relacionados à marca HBO e parceiros sem autorização de venda e/ou revenda.</li>
                            <li>Produtos relacionados à marca DISNEY e parceiros sem autorização de venda e/ou revenda.</li>
                            <li>Produtos relacionados à marca Spotify e parceiros sem autorização de venda e/ou revenda.</li>
                            <li>Capa Optfine (Minecraft).</li>
                            <li>Robux Gamepass (Roblox).</li>
                            <li>Contas Discord.</li>
                            <li>Ou qualquer outro produto/serviço que nossa equipe considere que possa ser prejudicial aos compradores e/ou que prejudique terceiros.</li>
                        </ul>
                    </div>

                    <!-- 8 -->
                    <div id="s8" class="legal-section">
                        <h3>8. Direitos de Propriedade Intelectual</h3>
                        <p>A Basefy respeita os direitos de propriedade intelectual de terceiros e requer que os Usuários façam o mesmo. Ao enviar qualquer conteúdo ou informação para o Site, incluindo textos, tais como comentários através da Ferramenta, fóruns de discussão, comunidades, enquetes, testes, seção de dúvidas, participação em concurso cultural, fotografias, ilustrações, vídeos, arquivos de áudio e outros materiais, o Usuário declara autorizar, de forma gratuita, não exclusiva, perpétua, global e livre de royalty, o uso do material pela Basefy e suas empresas afiliadas e parceiras, por qualquer modalidade e suporte.</p>
                        <p>Se o Usuário não concorda em autorizar a Basefy a utilizar sua contribuição conforme acima, o Usuário então não deverá submeter qualquer material para o Site. Todos os direitos autorais patrimoniais sobre o material submetido pelo Usuário continuam sendo de sua propriedade.</p>
                        <p>O Usuário reconhece e declara que em qualquer contribuição submetida para o Site, o material correspondente é de sua exclusiva criação, não constituindo violação de direitos autorais, marcas, segredos, direitos de personalidade.</p>
                        <p>Desde que citada a fonte e dentro das condições e limites previstos em lei, notadamente a Lei de Direitos Autorais (Lei n.º 9.610/98), o Usuário não pode reproduzir, publicar, apresentar, alugar, oferecer ou expor qualquer cópia de qualquer conteúdo pertencente à Basefy sem o consentimento da Basefy.</p>
                    </div>

                    <!-- 9 -->
                    <div id="s9" class="legal-section">
                        <h3>9. Denúncia de Abusos e Violação</h3>
                        <p>O Usuário se compromete a denunciar quaisquer abusos ou violação destes Termos e Condições de Uso ou de quaisquer direitos de terceiros que observar e/ou for vítima quando da utilização da Ferramenta. O Usuário pode denunciar, através da Ferramenta Report e Denunciar, qualquer usuário, anúncio ou pergunta/resposta. Caso queira, poderá procurar um moderador para reportar as violações e receber orientações.</p>
                        <p>Todo conteúdo que o Usuário publica utilizando a Ferramenta é uma informação que, por sua natureza e característica, é pública, aberta e não confidencial. Ao revelar dados pessoais, tais como seu nome e endereço de e-mail nos comentários, o Usuário aceita e compreende que essa informação pode ser coletada e usada por outras pessoas para se comunicarem com ele, sem que seja imputável qualquer responsabilidade à Basefy.</p>
                    </div>

                    <!-- 10 -->
                    <div id="s10" class="legal-section">
                        <h3>10. Isenção de Garantias e Limitação de Responsabilidade</h3>
                        <p>O Usuário isenta a Basefy de qualquer responsabilidade quanto à veracidade dos dados pessoais fornecidos por ele quando do uso da Ferramenta, bem como por qualquer violação a direitos de terceiros, ocorrida através da ferramenta no Site decorrentes de suas declarações.</p>
                        <p>Ao utilizar os serviços de intermediação da Basefy para realizar transações com vendedores independentes, é importante ter em mente que o vendedor determina as condições de compra, incluindo preço, pagamento, entrega, garantia, devolução, troca e reembolso. A Basefy não é responsável por quaisquer danos que possam ser causados durante essas transações.</p>
                        <p>Os serviços de intermediação fornecidos pela Basefy, incluindo informações, conteúdos, materiais, produtos digitais e outros serviços, são fornecidos "como estão". A Basefy não garante o nível de qualidade ou entrega em relação aos serviços, já que apenas realiza a intermediação entre comprador e vendedor.</p>
                        <p>Ao utilizar os serviços Basefy, o usuário concorda expressamente que o uso é por sua conta e risco exclusivo. A Basefy não é responsável por quaisquer danos ou prejuízos decorrentes da compra ou venda de produtos digitais entre vendedores independentes através da plataforma.</p>
                        <p>A Basefy não é proprietária dos produtos oferecidos pelos vendedores, não detém a posse deles, não realiza as ofertas de venda, tampouco, intervém na entrega dos produtos, cuja negociação se inicie no site.</p>
                        <p>A Basefy também não se responsabiliza pela transação efetuada entre Usuário e Comerciante, pelo efetivo cumprimento das obrigações assumidas pelos Comerciantes, nem pela existência, qualidade, estado, licitude, integridade ou legitimidade dos produtos oferecidos e/ou vendidos pelos Comerciantes no site.</p>
                        <p>A garantia do produto/serviço é de única e exclusivamente responsabilidade total do próprio vendedor.</p>
                        <p>Em casos de vendas de produtos digitais denominados GIFT CARDS, VOUCHERS ou CÓDIGOS DE ATIVAÇÃO, o vendedor concorda que fará a entrega do produto utilizando de meios de gravação para registrar a correta entrega do produto.</p>
                        <p>Em casos de compras de produtos digitais denominados GIFT CARDS, VOUCHERS ou CÓDIGOS DE ATIVAÇÃO, KEYS, CONTAS DE JOGOS "ALEATÓRIAS", o comprador concorda que estes não poderão ser reembolsados devido à sua natureza.</p>
                        <p>A data limite para SOLICITAÇÃO DE REEMBOLSO nas situações mencionadas na Política de Reembolso será informada no chat no início da compra, que coincide com a data de liberação para o vendedor retirar o dinheiro.</p>
                        <p>O usuário ao vender um produto/serviço declara e reconhece que a venda de itens por meio da plataforma Basefy caracteriza-se, conforme aplicável, como a comercialização de bens usados. O vendedor é integralmente responsável por verificar e cumprir todas as obrigações fiscais e tributárias decorrentes dessas transações.</p>
                    </div>

                    <!-- 11 -->
                    <div id="s11" class="legal-section">
                        <h3>11. Privacidade de Dados</h3>
                        <p>A Basefy não se responsabiliza por quaisquer danos que o Usuário possa sofrer, que tenham origem na divulgação dos dados pessoais do Usuário ou de terceiros em materiais publicados nos comentários. A Basefy preserva a privacidade dos dados dos Usuários, e se compromete a revelar os dados pessoais do Usuário apenas devido a um dos seguintes motivos: por lei; por meio de uma ordem ou intimação de um órgão, autoridade ou tribunal com poderes para tanto e de jurisdição competente; sob solicitação da parte lesada com justificativa e objetivo exclusivos para fins judiciais; para garantir a segurança dos sistemas, resguardar direitos e prevenir responsabilidades da Basefy.</p>
                    </div>

                    <!-- 12 -->
                    <div id="s12" class="legal-section">
                        <h3>12. Legislação Aplicável</h3>
                        <p>Estes Termos e Condições de Uso são governados e interpretados segundo as leis da República Federativa do Brasil e todas as disputas, ações e outros assuntos relacionados serão determinados de acordo com essa legislação.</p>
                        <p>Para solucionar eventuais controvérsias, as partes elegem o foro da comarca de Maringá — PR, renunciando a qualquer outro, por mais privilegiado que seja.</p>
                    </div>

                </div>
            </div>
        </div>
    </section>
</div>

<script>
function legalNav() {
    return {
        active: 's1',
        sections: [],
        init() {
            this.sections = [...document.querySelectorAll('.legal-section')];
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(e => {
                    if (e.isIntersecting) this.active = e.target.id;
                });
            }, { rootMargin: '-100px 0px -60% 0px', threshold: 0 });
            this.sections.forEach(s => observer.observe(s));
        },
        scrollTo(id) {
            document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    };
}
</script>

<?php
include __DIR__ . '/../views/partials/storefront_footer.php';
include __DIR__ . '/../views/partials/footer.php';
?>
