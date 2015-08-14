<?php
/*
 * Copyright (C) 2013   Cédric Salvador    <csalvador@gpcsolutions.fr>
 * Copyright (C) 2014-2015   ATM Consulting   <support@atm-consulting.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 *  \file       htdocs/product/stock/replenish.php
 *  \ingroup    produit
 *  \brief      Page to list stocks to replenish
 */

require 'config.php';
dol_include_once('/product/class/product.class.php');
dol_include_once('/core/class/html.formother.class.php');
dol_include_once('/core/class/html.form.class.php');
dol_include_once('/fourn/class/fournisseur.commande.class.php');
dol_include_once("/core/lib/admin.lib.php");
dol_include_once("/fourn/class/fournisseur.class.php");
dol_include_once('/supplierorderfromorder/lib/function.lib.php');
dol_include_once("/commande/class/commande.class.php");

global $bc, $conf, $db, $langs, $user;

$prod = new Product($db);

$langs->load("products");
$langs->load("stocks");
$langs->load("orders");
$langs->load("supplierorderfromorder@supplierorderfromorder");

$dolibarr_version35 = strpos(DOL_VERSION, "3.5") !== false;

/*echo "<form name=\"formCreateSupplierOrder\" method=\"post\" action=\"ordercustomer.php\">";*/

// Security check
if ($user->societe_id) {
    $socid = $user->societe_id;
}
$result=restrictedArea($user,'produit|service');

//checks if a product has been ordered

$action = GETPOST('action','alpha');
$sref = GETPOST('sref', 'alpha');
$snom = GETPOST('snom', 'alpha');
$sall = GETPOST('sall', 'alpha');
$type = GETPOST('type','int');
$tobuy = GETPOST('tobuy', 'int');
$salert = GETPOST('salert', 'alpha');

$sortfield = GETPOST('sortfield','alpha');
$sortorder = GETPOST('sortorder','alpha');
$page = GETPOST('page','int');

if (!$sortfield) {
    $sortfield = 'cd.rang';
}

if (!$sortorder) {
    $sortorder = 'ASC';
}
$conf->liste_limit = 1000; // Pas de pagination sur cet écran
$limit = $conf->liste_limit;
$offset = $limit * $page ;



/*
 * Actions
 */

if (isset($_POST['button_removefilter']) || isset($_POST['valid'])) {
    $sref = '';
    $snom = '';
    $sal = '';
    $salert = '';
}

/*echo "<pre>";
print_r($_REQUEST);
echo "</pre>";
exit;*/

		

//orders creation
//FIXME: could go in the lib
if ($action == 'order' && isset($_POST['valid'])) {
    $linecount = GETPOST('linecount', 'int');
    $box = false;
    unset($_POST['linecount']);
    if ($linecount > 0) {
        $suppliers = array();
        for ($i = 0; $i < $linecount; $i++) {
            if(GETPOST('check'.$i, 'alpha') === 'on' && (GETPOST('fourn' . $i, 'int') > 0 || GETPOST('fourn_free' . $i, 'int') > 0)) { //one line
            	
            	//echo GETPOST('tobuy_free'.$i).'<br>';
            	//Lignes de produit
            	if(!GETPOST('tobuy_free'.$i)){
	                $box = $i;
	                $supplierpriceid = GETPOST('fourn'.$i, 'int');
	                //get all the parameters needed to create a line
	                $qty = GETPOST('tobuy'.$i, 'int');
	                $desc = GETPOST('desc'.$i, 'alpha');
					
	                $sql = 'SELECT fk_product, fk_soc, ref_fourn';
	                $sql .= ', tva_tx, unitprice, remise_percent FROM ';
	                $sql .= MAIN_DB_PREFIX . 'product_fournisseur_price';
	                $sql .= ' WHERE rowid = ' . $supplierpriceid;
					
	                $resql = $db->query($sql);
					
	                if ($resql && $db->num_rows($resql) > 0) {
	                    //might need some value checks
	                    $obj = $db->fetch_object($resql);
	                    $line = new CommandeFournisseurLigne($db);
	                    $line->qty = $qty;
	                    $line->desc = $desc;
	                    $line->fk_product = $obj->fk_product;
	                    $line->tva_tx = $obj->tva_tx;
	                    $line->subprice = $obj->unitprice;
	                    $line->total_ht = $obj->unitprice * $qty;
	                    $tva = $line->tva_tx / 100;
	                    $line->total_tva = $line->total_ht * $tva;
	                    $line->total_ttc = $line->total_ht + $line->total_tva;
	                    $line->ref_fourn = $obj->ref_fourn;
						$line->remise_percent = $obj->remise_percent;
	                    // FIXME: Ugly hack to get the right purchase price since supplier references can collide
	                    // (eg. same supplier ref for multiple suppliers with different prices).
	                    $line->fk_prod_fourn_price = $supplierpriceid;
						
	                    if(!empty($_REQUEST['tobuy'.$i])) {
	                    	$suppliers[$obj->fk_soc]['lines'][] = $line;
	                    }
	
						
	                } else {
	                    $error=$db->lasterror();
	                    dol_print_error($db);
	                    dol_syslog('replenish.php: '.$error, LOG_ERR);
	                }
	                $db->free($resql);
	                unset($_POST['fourn' . $i]);
	        	}
				//Lignes libres
				else{
					//echo 'ok<br>';
					$box = $i;
					$qty = GETPOST('tobuy_free'.$i, 'int');
	                $desc = GETPOST('desc'.$i, 'alpha');
					$price = GETPOST('price_free'.$i, 'float');
					$lineid = GETPOST('lineid_free'.$i, 'int');
					$fournid = GETPOST('fourn_free'.$i, 'int');
					
					$commandeline = new OrderLine($db);
					$commandeline->fetch($lineid);
					
					$line = new CommandeFournisseurLigne($db);
                    $line->qty = $qty;
                    $line->desc = $desc;
                    $line->tva_tx = $commandeline->tva_tx;
                    $line->subprice = $price;
                    $line->total_ht = $price * $qty;
                    $tva = $line->tva_tx / 100;
                    $line->total_tva = $line->total_ht * $tva;
                    $line->total_ttc = $line->total_ht + $line->total_tva;
                    //$line->ref_fourn = $obj->ref_fourn;
					$line->remise_percent = $commandeline->remise_percent;
					
                    if(!empty($_REQUEST['tobuy_free'.$i])) {
                    	$suppliers[$fournid]['lines'][] = $line;
                    }
					unset($_POST['fourn_free' . $i]);
				}
            }
            unset($_POST[$i]);
        }

        //we now know how many orders we need and what lines they have
        $i = 0;
        $orders = array();
        $suppliersid = array_keys($suppliers);
        foreach ($suppliers as $idsupplier => $supplier) {
			
        	$sql2 = 'SELECT rowid, ref';
			$sql2 .= ' FROM ' . MAIN_DB_PREFIX . 'commande_fournisseur';
			$sql2 .= ' WHERE fk_soc = '.$idsupplier;
			$sql2 .= ' AND fk_statut = 0'; // 0 = DRAFT (Brouillon)
			$sql2 .= ' ORDER BY rowid DESC';
			$sql2 .= ' LIMIT 1';
						
			$res = $db->query($sql2);
			$obj = $db->fetch_object($res);
			
			//Si une commande au statut brouillon existe déjà et que l'option SOFO_CREATE_NEW_SUPPLIER_ODER_ANY_TIME
			if($obj && !$conf->global->SOFO_CREATE_NEW_SUPPLIER_ODER_ANY_TIME) {
				
				$commandeClient = new Commande($db);
				$commandeClient->fetch($_REQUEST['id']);
				
				$order = new CommandeFournisseur($db);
				$order->fetch($obj->rowid);
				$order->socid = $idsupplier;
				
				// On vérifie qu'il n'existe pas déjà un lien entre la commande client et la commande fournisseur dans la table element_element.
				// S'il n'y en a pas, on l'ajoute, sinon, on ne l'ajoute pas
				$order->fetchObjectLinked('', 'commande', $order->id, 'order_supplier');
				
				//if(count($order->linkedObjects) == 0) {

					$order->add_object_linked('commande', $_REQUEST['id']);
					
				//}
				if($conf->global->SOFO_GET_INFOS_FROM_ORDER){
					$order->mode_reglement_code = $commandeClient->mode_reglement_code;
					$order->mode_reglement_id = $commandeClient->mode_reglement_id;
					$order->cond_reglement_id = $commandeClient->cond_reglement_id;
					$order->cond_reglement_code = $commandeClient->cond_reglement_code;
					$order->date_livraison = $commandeClient->date_livraison;
				}
				
				$id++; //$id doit être renseigné dans tous les cas pour que s'affiche le message 'Vos commandes ont été générées'
				$newCommande = false;
			} else {
				
				$commandeClient = new Commande($db);
				$commandeClient->fetch($_REQUEST['id']);
				
				/*echo '<pre>';
				print_r($commandeClient);exit;*/
				
				$order = new CommandeFournisseur($db);
				$order->socid = $idsupplier;
				
				if($conf->global->SOFO_GET_INFOS_FROM_ORDER){
					$order->mode_reglement_code = $commandeClient->mode_reglement_code;
					$order->mode_reglement_id = $commandeClient->mode_reglement_id;
					$order->cond_reglement_id = $commandeClient->cond_reglement_id;
					$order->cond_reglement_code = $commandeClient->cond_reglement_code;
					$order->date_livraison = $commandeClient->date_livraison;
				}
				
				$id = $order->create($user);
				$order->add_object_linked('commande', $_REQUEST['id']);
				$newCommande = true;

			}
            //trick to know which orders have been generated this way
            $order->source = 42;
			
            foreach ($supplier['lines'] as $line) {

	            $done = false;
				
				$prodfourn = new ProductFournisseur($db);
				$prodfourn->fetch_product_fournisseur_price($_REQUEST['fourn'.$i]);

            	foreach($order->lines as $lineOrderFetched) {

            		if($line->fk_product == $lineOrderFetched->fk_product) {

                        $remise_percent = $lineOrderFetched->remise_percent;
                        if($line->remise_percent > $remise_percent)$remise_percent = $line->remise_percent;

            			$order->updateline(
                            $lineOrderFetched->id,
                            $lineOrderFetched->desc,
                            // FIXME: The current existing line may very well not be at the same purchase price
                            $lineOrderFetched->pu_ht,
                            $lineOrderFetched->qty + $line->qty,
                            $remise_percent,
                            $lineOrderFetched->tva_tx
                        );
						$done = true;
						break;

            		}

            	}

				// On ajoute une ligne seulement si un "updateline()" n'a pas été fait et si la quantité souhaitée est supérieure à zéro

				if(!$done) {

					$order->addline(
                        $line->desc,
                        $line->subprice,
                        $line->qty,
                        $line->tva_tx,
                        null,
                        null,
                        $line->fk_product,
                        // We need to pass fk_prod_fourn_price to get the right price.
                        $line->fk_prod_fourn_price,
                        null,
                        $line->remise_percent
                    );

				}

            }

            $order->cond_reglement_id = 0;
            $order->mode_reglement_id = 0;

            if ($id < 0) {
                $fail++; // FIXME: declare somewhere and use, or get rid of it!
                $msg = $langs->trans('OrderFail') . "&nbsp;:&nbsp;";
                $msg .= $order->error;
                setEventMessage($msg, 'errors');
            }
            $i++;
        }

		/*if($newCommande) {

			setEventMessage("Commande fournisseur créée avec succès !", 'errors');	
			
		} else {
			
			setEventMessage("Produits ajoutés à la commande en cours !", 'errors');
								
		}*/
			
        /*if (!$fail && $id) {
            setEventMessage($langs->trans('OrderCreated'), 'mesgs');
            //header('Location: '.DOL_URL_ROOT.'/commande/fiche.php?id='.$_REQUEST['id'].'');
        } else {
        	setEventMessage('coucou', 'mesgs');
        }*/
    }
    if ($box === false) {
        setEventMessage($langs->trans('SelectProduct'), 'warnings');
    } else {
    					
    	foreach($suppliers as $idSupplier => $lines) {
    		$j = 0;
    		foreach($lines as $line) {
		    	$sql = "SELECT quantity";
				$sql.= " FROM ".MAIN_DB_PREFIX."product_fournisseur_price";
				$sql.= " WHERE fk_soc = ".$idSupplier;
				$sql.= " AND fk_product = ".$line[$j]->fk_product;
				$sql.= " ORDER BY quantity ASC";
				$sql.= " LIMIT 1";
				$resql = $db->query($sql);
				if($resql){
					$resql = $db->fetch_object($resql);
					
					//echo $j;
					
					if($line[$j]->qty < $resql->quantity) {
						$p = new Product($db);
						$p->fetch($line[$j]->fk_product);
						$f = new Fournisseur($db);
						$f->fetch($idSupplier);
						$rates[$f->name] = $p->label;
					} else {
						$p = new Product($db);
						$p->fetch($line[$j]->fk_product);
						$f = new Fournisseur($db);
						$f->fetch($idSupplier);
						$ajoutes[$f->name] = $p->label;
					}
				}
				
				/*echo "<pre>";
				print_r($rates);
				echo "</pre>";
				echo "<pre>";
				print_r($ajoutes);
				echo "</pre>";*/
				$j++;
			}
		}
		$mess = "";
	    // FIXME: declare $ajoutes somewhere. It's unclear if it should be reinitialized or not in the interlocking loops.
		if($ajoutes) {
			foreach($ajoutes as $nomFournisseur => $nomProd) {
				$mess.= "Produit ' ".$nomProd." ' ajouté à la commande du fournisseur ' ".$nomFournisseur." '<br />";
			}
		}
	    // FIXME: same as $ajoutes.
		if($rates) {
			foreach($rates as $nomFournisseur => $nomProd) {
				$mess.= "Quantité insuffisante de ' ".$nomProd." ' pour le fournisseur ' ".$nomFournisseur." '<br />";
			}
		}
		if($rates) {
			setEventMessage($mess, 'warnings');
		} else {
			setEventMessage($mess, 'mesgs');
		}
    }
}

/*
 * View
 */
$title = $langs->trans('ProductsToOrder');

$sql = 'SELECT p.rowid, p.ref, p.label, cd.description, p.price, SUM(cd.qty) as qty, SUM(ed.qty) as expedie';
$sql .= ', p.price_ttc, p.price_base_type,p.fk_product_type';
$sql .= ', p.tms as datem, p.duration, p.tobuy, p.seuil_stock_alerte,';
$sql .= ' ( SELECT SUM(s.reel) FROM ' . MAIN_DB_PREFIX . 'product_stock s WHERE s.fk_product=p.rowid ) as stock_physique';
$sql .= $dolibarr_version35 ? ', p.desiredstock' : "";
$sql .= ' FROM ' . MAIN_DB_PREFIX . 'product as p';
$sql .= ' LEFT JOIN ' . MAIN_DB_PREFIX . 'commandedet as cd ON (p.rowid = cd.fk_product)';
$sql .= ' LEFT JOIN ' . MAIN_DB_PREFIX . 'expeditiondet as ed ON (ed.fk_origin_line = cd.rowid)';
//$sql .= ' LEFT JOIN ' . MAIN_DB_PREFIX . 'product_stock as s ON (p.rowid = s.fk_product)';
$sql .= ' WHERE p.entity IN (' . getEntity("product", 1) . ')';

$fk_commande = GETPOST('id','int');

if($fk_commande > 0) $sql .= ' AND cd.fk_commande = '.$fk_commande;

if ($sall) {
    $sql .= ' AND (p.ref LIKE "%'.$db->escape($sall).'%" ';
    $sql .= 'OR p.label LIKE "%'.$db->escape($sall).'%" ';
    $sql .= 'OR p.description LIKE "%'.$db->escape($sall).'%" ';
    $sql .= 'OR p.note LIKE "%'.$db->escape($sall).'%")';
}
// if the type is not 1, we show all products (type = 0,2,3)
if (dol_strlen($type)) {
    if ($type == 1) {
        $sql .= ' AND p.fk_product_type = 1';
    } else {
        $sql .= ' AND p.fk_product_type != 1';
    }
}
if ($sref) {
    //natural search
    $scrit = explode(' ', $sref);
    foreach ($scrit as $crit) {
        $sql .= ' AND p.ref LIKE "%' . $crit . '%"';
    }
}
if ($snom) {
    //natural search
    $scrit = explode(' ', $snom);
    foreach ($scrit as $crit) {
        $sql .= ' AND p.label LIKE "%' . $db->escape($crit) . '%"';
    }
}

$sql .= ' AND p.tobuy = 1';

if (!empty($canvas)) {
    $sql .= ' AND p.canvas = "' . $db->escape($canvas) . '"';
}
$sql .= ' GROUP BY p.rowid, p.ref, p.label, p.price';
$sql .= ', p.price_ttc, p.price_base_type,p.fk_product_type, p.tms';
$sql .= ', p.duration, p.tobuy, p.seuil_stock_alerte';
//$sql .= ', p.desiredstock'; 
//$sql .= ', s.fk_product';

if(!empty($conf->global->SUPPORDERFROMORDER_USE_ORDER_DESC)) {
	$sql.= ', cd.description';
}
//$sql .= ' HAVING p.desiredstock > SUM(COALESCE(s.reel, 0))';
//$sql .= ' HAVING p.desiredstock > 0';
if ($salert == 'on') {
    $sql .= ' HAVING SUM(COALESCE(s.reel, 0)) < p.seuil_stock_alerte AND p.seuil_stock_alerte is not NULL';
    $alertchecked = 'checked="checked"';
}

$sql2 = '';
//On prend les lignes libre
if($_REQUEST['id'] && $conf->global->SOFO_ADD_FREE_LINES){
	$sql2 .= 'SELECT cd.rowid, cd.description, SUM(cd.qty) as qty, cd.product_type, cd.price
			 FROM '.MAIN_DB_PREFIX.'commandedet as cd
			 	LEFT JOIN '.MAIN_DB_PREFIX.'commande as c ON (cd.fk_commande = c.rowid)
			 WHERE c.rowid = '.$_REQUEST['id'].' AND fk_product IS NULL';
	if(!empty($conf->global->SUPPORDERFROMORDER_USE_ORDER_DESC)) {
		$sql2 .= ' GROUP BY cd.description';
	}
	//echo $sql2;
}
$sql .= $db->order($sortfield,$sortorder);
if(!$conf->global->SOFO_USE_DELIVERY_TIME) $sql .= $db->plimit($limit + 1, $offset);
$resql = $db->query($sql);

if($sql2 && $fk_commande > 0){
	$sql2 .= $db->order($sortfield,$sortorder);
	$sql2 .= $db->plimit($limit + 1, $offset);
	$resql2 = $db->query($sql2);
}

if ($resql || $resql2) {
    $num = $db->num_rows($resql);
	$num2 = $db->num_rows($resql2);
    $i = 0;

    $helpurl = 'EN:Module_Stocks_En|FR:Module_Stock|';
    $helpurl .= 'ES:M&oacute;dulo_Stocks';
    llxHeader('', $title, $helpurl, $title);
    $head = array();
    $head[0][0] = dol_buildpath('/supplierorderfromorder/ordercustomer.php?id='.$_REQUEST['id'],2);
    $head[0][1] = $title;
    $head[0][2] = 'supplierorderfromorder';
	/*$head[1][0] = DOL_URL_ROOT.'/product/stock/replenishorders.php';
	$head[1][1] = $langs->trans("ReplenishmentOrders");
	$head[1][2] = 'replenishorders';*/
    dol_fiche_head($head, 'supplierorderfromorder', $langs->trans('Replenishment'), 0, 'stock');
	
	
	
	if ($sref || $snom || $sall || $salert || GETPOST('search', 'alpha')) {
        $filters = '&sref=' . $sref . '&snom=' . $snom;
        $filters .= '&sall=' . $sall;
        $filters .= '&salert=' . $salert;
		
		if(!$conf->global->SOFO_USE_DELIVERY_TIME ) {
			
			 print_barre_liste(
	        		$texte,
	        		$page,
	        		'ordercustomer.php',
	        		$filters,
	        		$sortfield,
	        		$sortorder,
	        		'',
	        		 $num);
	        		
	       	
		}
        
    } else {
        $filters = '&sref=' . $sref . '&snom=' . $snom;
        $filters .= '&fourn_id=' . $fourn_id;
        $filters .= (isset($type)?'&type=' . $type:'');
        $filters .=  '&salert=' . $salert;
        
        if(!$conf->global->SOFO_USE_DELIVERY_TIME ) {
		
        print_barre_liste(
        		$texte,
        		$page,
        		'ordercustomer.php',
        		$filters,
        		$sortfield,
        		$sortorder,
        		'',
        		$num
        		
        );
		
		}
    }

    print '<form action="'.$_SERVER['PHP_SELF'].'" method="post" name="formulaire">'.
         '<input type="hidden" name="id" value="' .$_REQUEST['id'] . '">'.
         '<input type="hidden" name="token" value="' .$_SESSION['newtoken'] . '">'.
         '<input type="hidden" name="sortfield" value="' . $sortfield . '">'.
         '<input type="hidden" name="sortorder" value="' . $sortorder . '">'.
         '<input type="hidden" name="type" value="' . $type . '">'.
         '<input type="hidden" name="linecount" value="' . ($num+$num2) . '">'.
         '<input type="hidden" name="action" value="order">'.
         '<input type="hidden" name="fk_commande" value="' . GETPOST('fk_commande','int'). '">'.
         '<table class="liste" width="100%">';

    if($conf->global->SOFO_USE_DELIVERY_TIME) {
            $week_to_replenish = (int)GETPOST('week_to_replenish','int');
        
            $colspan = empty($conf->global->FOURN_PRODUCT_AVAILABILITY) ? 7 : 8;
            
        print '<tr class="liste_titre">'.
            '<td colspan="'.$colspan.'">'.$langs->trans('NbWeekToReplenish').'<input type="text" name="week_to_replenish" value="'.$week_to_replenish.'" size="2"> '
            .'<input type="submit" value="'.$langs->trans('ReCalculate').'" /></td></tr>';
        
        
    }


    $param = (isset($type)? '&type=' . $type : '');
    $param .= '&fourn_id=' . $fourn_id . '&snom='. $snom . '&salert=' . $salert;
    $param .= '&sref=' . $sref;

    // Lignes des titres
    print '<tr class="liste_titre">'.
         '<td><input type="checkbox" onClick="toggle(this)" /></td>';
    print_liste_field_titre(
    		$langs->trans('Ref'),
    		'ordercustomer.php',
    		'p.ref',
    		$param,
    		'id='.$_REQUEST['id'],
    		'',
    		$sortfield,
    		$sortorder
    );
    print_liste_field_titre(
    		$langs->trans('Label'),
    		'ordercustomer.php',
    		'p.label',
    		$param,
    		'id='.$_REQUEST['id'],
    		'',
    		$sortfield,
    		$sortorder
    );
    if (!empty($conf->service->enabled) && $type == 1) 
    {
    	print_liste_field_titre(
    			$langs->trans('Duration'),
    			'ordercustomer.php',
    			'p.duration',
    			$param,
    			'id='.$_REQUEST['id'],
    			'align="center"',
    			$sortfield,
    			$sortorder
    	);
    }

	if($dolibarr_version35) {
	    print_liste_field_titre(
	    		$langs->trans('DesiredStock'),
	    		'ordercustomer.php',
	    		'p.desiredstock',
	    		$param,
	    		'id='.$_REQUEST['id'],
	    		'align="right"',
	    		$sortfield,
	    		$sortorder
	    );
	}
    if ($conf->global->USE_VIRTUAL_STOCK) 
    {
        $stocklabel = $langs->trans('VirtualStock');
    }
    else 
    {
        $stocklabel = $langs->trans('PhysicalStock');
    }
    print_liste_field_titre(
    		$stocklabel,
    		'ordercustomer.php',
    		'stock_physique',
    		$param,
    		'id='.$_REQUEST['id'],
    		'align="right"',
    		$sortfield,
    		$sortorder
    );
    print_liste_field_titre(
    		$langs->trans('Ordered'),
    		'ordercustomer.php',
    		'',
    		$param,
    		'id='.$_REQUEST['id'],
    		'align="right"',
    		$sortfield,
    		$sortorder
    );
    print_liste_field_titre(
    		$langs->trans('StockToBuy'),
    		'ordercustomer.php',
    		'',
    		$param,
    		'id='.$_REQUEST['id'],
    		'align="right"',
    		$sortfield,
    		$sortorder
    );

   	if (!empty($conf->global->FOURN_PRODUCT_AVAILABILITY)) print_liste_field_titre($langs->trans("Availability"));
	
    print_liste_field_titre(
    		$langs->trans('Supplier'),
    		'ordercustomer.php',
    		'',
    		$param,
    		'id='.$_REQUEST['id'],
    		'align="right"',
    		$sortfield,
    		$sortorder
    );
    print '<td>&nbsp;</td>'.
         '</tr>'.

	    // Lignes des champs de filtre
         '<tr class="liste_titre">'.
         '<td class="liste_titre">&nbsp;</td>'.
         '<td class="liste_titre">'.
         '<input class="flat" type="text" name="sref" value="' . $sref . '">'.
         '</td>'.
         '<td class="liste_titre">'.
         '<input class="flat" type="text" name="snom" value="' . $snom . '">'.
         '</td>';
    if (!empty($conf->service->enabled) && $type == 1) 
    {
        print '<td class="liste_titre">'.
             '&nbsp;'.
             '</td>';
    }
	
	$liste_titre = "";
    $liste_titre.= $dolibarr_version35 ? '<td class="liste_titre">&nbsp;</td>' : '';
    $liste_titre.= '<td class="liste_titre" align="right">' . $langs->trans('AlertOnly') . '&nbsp;<input type="checkbox" name="salert" ' . $alertchecked . '></td>'.
         '<td class="liste_titre" align="right">&nbsp;</td>'.
         '<td class="liste_titre">&nbsp;</td>'.
         '<td class="liste_titre" '.($conf->global->SOFO_USE_DELIVERY_TIME ? 'colspan="2"' : '').'>&nbsp;</td>'.
         '<td class="liste_titre" align="right">'.
         '<input type="image" class="liste_titre" name="button_search"'.
         'src="' . DOL_URL_ROOT . '/theme/' . $conf->theme . '/img/search.png" alt="' . $langs->trans("Search") . '">'.
         '<input type="image" class="liste_titre" name="button_removefilter"
          src="'.DOL_URL_ROOT.'/theme/'.$conf->theme.'/img/searchclear.png" value="'.dol_escape_htmltag($langs->trans("Search")).'" title="'.dol_escape_htmltag($langs->trans("Search")).'">'.
         '</td>'.
         '</tr>';
		 
	print $liste_titre;

    $prod = new Product($db);

    $var = True;
    $form = new Form($db);
			
	if($conf->global->SOFO_USE_DELIVERY_TIME) {
		$form->load_cache_availability();	
		$limit = 999999;
	}
	
    while ($i < min($num, $limit)) {
    	$objp = $db->fetch_object($resql);
        if ($conf->global->STOCK_SUPPORTS_SERVICES
           || $objp->fk_product_type == 0) {
            // Multilangs
            if (! empty($conf->global->MAIN_MULTILANGS)) {
                $sql = 'SELECT label';
                $sql .= ' FROM ' . MAIN_DB_PREFIX . 'product_lang';
                $sql .= ' WHERE fk_product = ' . $objp->rowid;
                $sql .= ' AND lang = "' . $langs->getDefaultLang() . '"';
                $sql .= ' LIMIT 1';

                $result = $db->query($sql);
                if ($result) {
                    $objtp = $db->fetch_object($result);
                    if (!empty($objtp->label)) {
                        $objp->label = $objtp->label;
                    }
                }
            }
            
			
			
			
            
            $prod->ref = $objp->ref;
            $prod->id = $objp->rowid;
            $prod->type = $objp->fk_product_type;
            //$ordered = ordered($prod->id);

            $help_stock =  $langs->trans('PhysicalStock').' : '.(float)$objp->stock_physique;
            
							
            if($week_to_replenish>0) {
            	/* là ça déconne pas, on s'en fout, on dépote ! */
            	
            	$stock_commande_client = _load_stats_commande_date($prod->id, date('Y-m-d',strtotime('+'.$week_to_replenish.'week') ) );
				$stock_commande_fournisseur = _load_stats_commande_fournisseur($prod->id, date('Y-m-d',strtotime('+'.$week_to_replenish.'week')), $objp->stock_physique-$stock_commande_client);
		
				$help_stock.=', '.$langs->trans('Orders').' : '.(float)$stock_commande_client;
            	$help_stock.=', '.$langs->trans('SupplierOrders').' : '.(float)$stock_commande_fournisseur;
            
		
				$stock = $objp->stock_physique - $stock_commande_client + $stock_commande_fournisseur;
            }
			else if ($conf->global->USE_VIRTUAL_STOCK || $conf->global->SOFO_USE_VIRTUAL_ORDER_STOCK) {
                //compute virtual stock
                $prod->fetch($prod->id);
				
				if(!$conf->global->STOCK_CALCULATE_ON_VALIDATE_ORDER || $conf->global->SOFO_USE_VIRTUAL_ORDER_STOCK) {
	                $result=$prod->load_stats_commande(0, '1,2');
	                if ($result < 0) {
	                    dol_print_error($db, $prod->error);
	                }
	                $stock_commande_client = $prod->stats_commande['qty'];
					
				}
				else{
					$stock_commande_client = 0;	
				}
				
				if(!$conf->global->STOCK_CALCULATE_ON_SUPPLIER_VALIDATE_ORDER || $conf->global->SOFO_USE_VIRTUAL_ORDER_STOCK) {
	                $result=$prod->load_stats_commande_fournisseur(0, '3');
	                if ($result < 0) {
	                    dol_print_error($db,$prod->error);
	                }
					$stock_commande_fournisseur = $prod->stats_commande_fournisseur['qty'];
				}
				else{
					$stock_commande_fournisseur = 0;
				}
				$help_stock.=', '.$langs->trans('Orders').' : '.(float)$stock_commande_client;
            	$help_stock.=', '.$langs->trans('SupplierOrders').' : '.(float)$stock_commande_fournisseur;
            
                $stock = $objp->stock_physique - $stock_commande_client + $stock_commande_fournisseur;
				
            } else {
            	$stock_commande_client = $objp->qty;
                $stock = $objp->stock_physique - $stock_commande_client;
				
				$help_stock.=', '.$langs->trans('Orders').' : '.(float)$stock_commande_client;
            
            }
			
			$ordered = $stock_commande_client;
			
			$stock_expedie_client = $objp->expedie;
			
        	//if($objp->rowid == 14978)	{print "$stock >= {$objp->qty} - $stock_expedie_client + {$objp->desiredstock}";exit;}
            if($stock >= (float)$objp->qty - (float)$stock_expedie_client + (float)$objp->desiredstock) {
    			$i++;
    			continue; // le stock est suffisant on passe
    		}
			$var =! $var;
            
            $warning='';
            if ($objp->seuil_stock_alerte
                && ($stock < $objp->seuil_stock_alerte)) {
                    $warning = img_warning($langs->trans('StockTooLow')) . ' ';
            }
				
			// On regarde s'il existe une demande de prix en cours pour ce produit
			$TDemandes = array();
			
			if($conf->askpricesupplier->enabled) {
				
				$q = 'SELECT a.ref
						FROM '.MAIN_DB_PREFIX.'askpricesupplier a
						INNER JOIN '.MAIN_DB_PREFIX.'askpricesupplierdet d on (d.fk_askpricesupplier = a.rowid)
						WHERE a.fk_statut = 1
						AND fk_product = '.$prod->id;
				
				$qres = $db->query($q);
				
				while($res = $db->fetch_object($qres)) $TDemandes[] = $res->ref;
				
          	}
          	
            print '<tr ' . $bc[$var] . '>'.
                 '<td><input type="checkbox" class="check" name="check' . $i . '"' . $disabled . '></td>'.
                 '<td class="nowrap">'.
                 (!empty($TDemandes) ? $form->textwithpicto($prod->getNomUrl(1), 'Demande(s) de prix en cours :<br />'.implode(', ', $TDemandes), 1, 'help') : $prod->getNomUrl(1)).
                 '</td>'.
                 '<td>' . $objp->label . '</td>';

				if(!empty($conf->global->SUPPORDERFROMORDER_USE_ORDER_DESC)) {
					print '<input type="hidden" name="desc' . $i . '" value="' . $objp->description . '" >';
				}

            if (!empty($conf->service->enabled) && $type == 1) {
                if (preg_match('/([0-9]+)y/i', $objp->duration, $regs)) {
                    $duration =  $regs[1] . ' ' . $langs->trans('DurationYear');
                } elseif (preg_match('/([0-9]+)m/i', $objp->duration, $regs)) {
                    $duration =  $regs[1] . ' ' . $langs->trans('DurationMonth');
                } elseif (preg_match('/([0-9]+)d/i', $objp->duration, $regs)) {
                    $duration =  $regs[1] . ' ' . $langs->trans('DurationDay');
                } else {
                    $duration = $objp->duration;
                }-4 >= - 
                print '<td align="center">'.
                     $duration.
                     '</td>';
            }


			// La quantité à commander correspond au stock désiré sur le produit additionné à la quantité souhaitée dans la commande :
			$stocktobuy = $objp->desiredstock - ($stock - $stock_expedie_client);
			
			$help_stock.=', ' .$langs->trans('Expeditions').' : '.(float)$stock_expedie_client;
			
			if($conf->asset->enabled) {
				
				/* Si j'ai des OF je veux savoir combien cela me coûte */
				
				define('INC_FROM_DOLIBARR', true);
				dol_include_once('/asset/config.php');
				dol_include_once('/asset/class/ordre_fabrication_asset.class.php');
				
				$stock_of_needed = TAssetOF::getProductNeededQty($prod->id, true, false, date('Y-m-d',strtotime('+'.$week_to_replenish.'week') ));
				$stock_of_tomake = TAssetOF::getProductNeededQty($prod->id, true, false, date('Y-m-d',strtotime('+'.$week_to_replenish.'week') ), 'TO_MAKE');
				$stocktobuy += $stock_of_needed - $stock_of_tomake;
							
				$help_stock.=', '.$langs->trans('OF').' : '.(float)($stock_of);
			}
			
			$help_stock.=', '.$langs->trans('DesiredStock').' : '.(float)$objp->desiredstock;
							
			
			if($stocktobuy < 0) $stocktobuy = 0;


            //print $dolibarr_version35 ? '<td align="right">' . $objp->desiredstock . '</td>' : "".
            
            	$champs = "";
            	$champs .= $dolibarr_version35 ? '<td align="right">' . $objp->desiredstock . '</td>' : '';
                $champs.= '<td align="right">'.
                 $warning . $stock.
                 '</td>'.
                 '<td align="right">'.
                 
                 $ordered  . $picto.
                 '</td>'.
                 '<td align="right">'.
                 '<input type="text" name="tobuy' . $i .
                 '" value="' . $stocktobuy . '" ' . $disabled . ' size="4">'.img_help(1, $help_stock)
                 .'</td>';
				 
				 if($conf->global->SOFO_USE_DELIVERY_TIME) {
				
					$nb_day = (int)getMinAvailability($objp->rowid,$stocktobuy);
				
					$champs.= '<td>'.($nb_day == 0 ? $langs->trans('Unknown') : $nb_day.' '.$langs->trans('Days')).'</td>';
				
				}
			

				 
				 
                 $champs.='<td align="right">'.
                 $form->select_product_fourn_price($prod->id, 'fourn'.$i, 1).
                 '</td>';
				print $champs;
				
       if($conf->asset->enabled && $user->rights->asset->of->write) {
		print '<td><a href="'.dol_buildpath('/asset/fiche_of.php',1).'?action=new&fk_product='.$prod->id.'" class="butAction">Fabriquer</a></td>';
	   }
	   else {
	    	print '<td>&nbsp</td>';
	   }
           print '</tr>';
        }
        $i++;
    }
	
	//Lignes libre
	if($resql2){
		while ($j< min($num, $limit)) {
	        $objp = $db->fetch_object($resql2);
			if ($objp->product_type == 0) $picto = img_object($langs->trans("ShowProduct"),'product');
			if ($objp->product_type == 1) $picto = img_object($langs->trans("ShowService"),'service');
			
            print '<tr ' . $bc[$var] . '>'.
                 '<td><input type="checkbox" class="check" name="check' . $i . '"' . $disabled . '></td>'.
                 '<td class="nowrap">'.
                 $picto." ".$objp->description.
                 '</td>'.
                 '<td>' . $objp->description . '</td>';
			
			$picto = img_picto('', './img/no', '', 1);
			
			if(!empty($conf->global->SUPPORDERFROMORDER_USE_ORDER_DESC)) {
				print '<input type="hidden" name="desc' . $i . '" value="' . $objp->description . '" >';
			}
		
            if (!empty($conf->service->enabled) && $type == 1) {
                if (preg_match('/([0-9]+)y/i', $objp->duration, $regs)) {
                    $duration =  $regs[1] . ' ' . $langs->trans('DurationYear');
                } elseif (preg_match('/([0-9]+)m/i', $objp->duration, $regs)) {
                    $duration =  $regs[1] . ' ' . $langs->trans('DurationMonth');
                } elseif (preg_match('/([0-9]+)d/i', $objp->duration, $regs)) {
                    $duration =  $regs[1] . ' ' . $langs->trans('DurationDay');
                } else {
                    $duration = $objp->duration;
                }
                print '<td align="center">'.
                     $duration.
                     '</td>';
            }
			
			print '<td align="right">'.$picto.'</td>';
			print '<td align="right">'.$picto.'</td>';
		    print '<td align="right">'.
                 '<input type="text" name="tobuy_free' . $i .
                 '" value="' . $objp->qty . '">'.
                 '</td>';
			
			print '<input type="hidden" name="lineid_free' . $i . '" value="' . $objp->rowid . '" >';
			
			print '<td align="right">
						<input type="text" name="price_free'.$i.'" value="'.$objp->price.'" size="5" style="text-align:right">€
						'.$form->select_company((empty($socid)?'':$socid),'fourn_free'.$i,'s.fournisseur = 1',1).'
				   </td>';

	        print '</tr>';
	        $i++; $j++;
	    }
    }

    $value = $langs->trans("GenerateSupplierOrder");
    print '</table>'.
         '</div>'.
         '<table width="100%">'.
         '<tr><td align="right">'.
         '<input class="butAction" type="submit" name="valid" value="' . $value . '">'.
         '</td></tr></table>'.
         '</form>';

    if ($num > $conf->liste_limit) 
    {
        if ($sref || $snom || $sall || $salert || GETPOST('search', 'alpha')) 
        {
            $filters = '&sref=' . $sref . '&snom=' . $snom;
            $filters .= '&sall=' . $sall;
            $filters .= '&salert=' . $salert;
            print_barre_liste(
            		'',
            		$page,
            		'replenish.php',
            		$filters,
            		$sortfield,
            		$sortorder,
            		'',
            		$num,
            		0,
            		''
            );
        } else {
            $filters = '&sref=' . $sref . '&snom=' . $snom;
            $filters .= '&fourn_id=' . $fourn_id;
            $filters .= (isset($type)? '&type=' . $type : '');
            $filters .= '&salert=' . $salert;
            print_barre_liste(
            		'',
            		$page,
            		'replenish.php',
            		$filters,
            		$sortfield,
            		$sortorder,
            		'',
            		$num,
            		0,
            		''
            );
        }
    }

    $db->free($resql);
print ' <script type="text/javascript">
     function toggle(source)
     {
       var checkboxes = document.getElementsByClassName("check");
       for (var i=0; i < checkboxes.length;i++) {
         if (!checkboxes[i].disabled) {
            checkboxes[i].checked = source.checked;
        }
       }
     } </script>';
} else {
    dol_print_error($db);
}

llxFooter();

$db->close();

function _load_stats_commande_fournisseur($fk_product, $date,$stocktobuy=1,$filtrestatut='3') {
	global $conf,$user,$db;

	$nb_day = (int)getMinAvailability($fk_product,$stocktobuy);
	$date = date('Y-m-d', strtotime('-'.$nb_day.'day',  strtotime($date)));

	$sql = "SELECT SUM(cd.qty) as qty";
	$sql.= " FROM ".MAIN_DB_PREFIX."commande_fournisseurdet as cd";
	$sql.= ", ".MAIN_DB_PREFIX."commande_fournisseur as c";
	$sql.= ", ".MAIN_DB_PREFIX."societe as s";
	$sql.= " WHERE c.rowid = cd.fk_commande";
	$sql.= " AND c.fk_soc = s.rowid";
	$sql.= " AND c.entity = ".$conf->entity;
	$sql.= " AND cd.fk_product = ".$fk_product;
	$sql.= " AND (c.date_livraison IS NULL OR c.date_livraison<='".$date."') ";
	if ($filtrestatut != '') $sql.= " AND c.fk_statut in (".$filtrestatut.")"; 
	
	$result =$db->query($sql);
	if ( $result )
	{
			$obj = $db->fetch_object($result);
			return (float)$obj->qty;
	}
	else
	{
		
		return 0;
	}
}

function _load_stats_commande_date($fk_product, $date,$filtrestatut='1,2') {
		global $conf,$user,$db;
	
		$sql = "SELECT SUM(cd.qty) as qty";
		$sql.= " FROM ".MAIN_DB_PREFIX."commandedet as cd";
		$sql.= ", ".MAIN_DB_PREFIX."commande as c";
		$sql.= ", ".MAIN_DB_PREFIX."societe as s";
		$sql.= " WHERE c.rowid = cd.fk_commande";
		$sql.= " AND c.fk_soc = s.rowid";
		$sql.= " AND c.entity = ".$conf->entity;
		$sql.= " AND cd.fk_product = ".$fk_product;
		$sql.= " AND (c.date_livraison IS NULL OR c.date_livraison<='".$date."') ";
		if ($filtrestatut <> '') $sql.= " AND c.fk_statut in (".$filtrestatut.")";
		
		$result =$db->query($sql);
		if ( $result )
		{
				$obj = $db->fetch_object($result);
				return (float)$obj->qty;
		}
		else
		{
			
			return 0;
		}
}
