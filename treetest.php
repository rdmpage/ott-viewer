<?php

error_reporting(E_ALL);

require_once('tree/node.php');
require_once('tree/tree.php');
require_once('tree/node_iterator.php');
require_once('tree/tree-parse.php');
require_once('tree/tree-order.php');
require_once('tree/svg.php');


// compute x,y coordinates for a tree
function treexy(&$t, $width = 200, $height = 200)
{
	$left   = 0;
	$top    = 0;
		
	// weights
	$n = new NodeIterator ($t->GetRoot());
	$q = $n->Begin();
	while ($q != NULL)
	{	
		if ($q->IsLeaf())
		{
			$q->weight = 1;
		}
	
		$anc = $q->GetAncestor();
		if ($anc)
		{
			$anc->weight += $q->weight;
		}
		$q = $n->Next();
	}
	
	// depth
	$max_depth = 0;
	$q = $n->Begin();
	while ($q != NULL)
	{	
		if ($q->IsLeaf())
		{
			$q->depth = 0;
			$count = 0;
			$p = $q->GetAncestor();
			while ($p)
			{
				$count++;
				if ($count > $p->depth)
				{
					$p->depth = $count;
					$max_depth = max($max_depth, $p->depth);
				}
				
				$p = $p->GetAncestor();
			}
		}
		$q = $n->Next();
	}
	
	if (0)
	{
		$x_gap = $width  / ($t->GetNumLeaves() - 1);
	}
	else
	{
		$x_gap = $width  / $max_depth;
	}
	$y_gap = $height / ($t->GetNumLeaves() - 1);
	$last_y = 0;
	
	$leaf_count = 0;
	
	// coordinates
	$q = $n->Begin();
	while ($q != NULL)
	{	
		if ($q->IsLeaf())
		{
			$q->x = $left + $width;
			$q->y = $top + $leaf_count * $y_gap;
			
			$leaf_count++;
			$last_y = $q->y;
		}
		else
		{
			// internal
			if (0)
			{
				$q->x = $left + ($x_gap * ($t->GetNumLeaves() - $q->weight));
			}
			else
			{
				$q->x = $left + ($x_gap * ($max_depth - $q->depth));
			}			
				
			if (0)
			{
				$q->y = $last_y - ($q->weight - 1) * $y_gap / 2.0;
			}
			else
			{			
				$span = $q->GetChild()->GetRightMostSibling()->y - $q->GetChild()->y;			
				$q->y = $q->GetChild()->y + $span/2;
			}
		}
		
		$q = $n->Next();
	}
}

// draw tree to SVG
function drawtree($t, $width = 200, $height = 200)
{
	// draw
	
	// SVG diagram
	$port = new SVGPort('', $width, $height, 10, false);
	$port->StartGroup('tree', true);
	
	foreach ($t->id_to_node_map as $id => $node)
	{
		$anc = $node->GetAncestor();
		if ($anc)
		{
			$port->DrawLine
			(
				['x' => $node->x, 'y' => $node->y],
				['x' => $anc->x, 'y' => $anc->y]
			);
		}
	}
	
	$port->EndGroup();
	$svg = $port->GetOutput();
	
	return $svg;
}


$newick = "((((((((((mrcaott319472ott449678[&type=leaf,weight=2],'Pterodroma phaeopygia'[&type=leaf,weight=1,supertree_leaf=1])mrcaott319472ott485717[&type=internal,weight=3],'Pterodroma externa'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271035ott319472[&type=internal,weight=4],'Pterodroma cervicalis'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271035ott461783[&type=internal,weight=5],'Pterodroma ultima'[&type=leaf,weight=1,supertree_leaf=1])mrcaott73703ott271035[&type=internal,weight=6],((mrcaott285635ott461784[&type=leaf,weight=2],'Pterodroma arminjoniana'[&type=leaf,weight=1,supertree_leaf=1])mrcaott285635ott666330[&type=internal,weight=3],'Pterodroma neglecta'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271044ott285635[&type=internal,weight=4])mrcaott73703ott271044[&type=internal,weight=10],'Pterodroma inexpectata'[&type=leaf,weight=1,supertree_leaf=1])mrcaott73703ott85282[&type=internal,weight=11],'Pterodroma axillaris'[&type=leaf,weight=1,supertree_leaf=1])mrcaott73703ott845409[&type=internal,weight=12],mrcaott319464ott485716[&type=leaf,weight=2])mrcaott73703ott319464[&type=internal,weight=14],(((mrcaott3595611ott4947457[&type=leaf,weight=2],'Pterodroma pycrofti'[&type=leaf,weight=1,supertree_leaf=1])mrcaott3595611ott3595620[&type=internal,weight=3],'Pterodroma cookii'[&type=leaf,weight=1,supertree_leaf=1])mrcaott845415ott3595611[&type=internal,weight=4],'Pterodroma longirostris'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271031ott845415[&type=internal,weight=5])mrcaott73703ott271031[&type=internal,weight=19],other_Pterodroma[&type=other,count=6,weight=6],((((((mrcaott713614ott950182[&type=leaf,weight=2],'Pterodroma cahow'[&type=leaf,weight=1,supertree_leaf=1])mrcaott713614ott845408[&type=internal,weight=3],'Pterodroma hasitata'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271036ott713614[&type=internal,weight=4],'Pterodroma imberi'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271036ott5925783[&type=internal,weight=5],((('Pterodroma macroptera'[&type=leaf,weight=2],'Pterodroma lessonii'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271034ott581903[&type=internal,weight=3],'Pterodroma incerta'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271033ott271034[&type=internal,weight=4],'Pterodroma magentae'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271029ott271033[&type=internal,weight=5])mrcaott271029ott271036[&type=internal,weight=10],'Pterodroma mollis'[&type=leaf,weight=2])mrcaott271029ott271043[&type=internal,weight=12],'Pterodroma hypoleuca'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271029ott797520[&type=internal,weight=13],'Pterodroma wortheni'[&type=leaf,weight=1,supertree_leaf=1],'Pterodroma oliveri'[&type=leaf,weight=1,supertree_leaf=1],'Pterodroma deceptornis'[&type=leaf,weight=1,supertree_leaf=1],'Pterodroma chionophara'[&type=leaf,weight=1,supertree_leaf=1])Pterodroma[&type=internal,weight=42];";

//$newick = "((a,b)x,(c,(d,e)y)z)root;";

$t = parse_newick($newick);

//echo $t->WriteNewick() . "\n";

/*
foreach ($t->id_to_node_map as $id => $node)
{
	echo $id . " " . $node->label . "\n";
}
*/

$newick_strings = [
'((a,b)x,(c,(d,e)y)z)root;',
'(c,(d,(f,g,h)e)y)z;',
];

$newick_strings = [
"(((('Rhea rothschildi'[&type=leaf,weight=1,supertree_leaf=1],'Rhea macrorhyncha'[&type=leaf,weight=1,supertree_leaf=1],'Rhea subpampeana'[&type=leaf,weight=1,supertree_leaf=1],'Rhea pampeana'[&type=leaf,weight=1,supertree_leaf=1],'Rhea fossilis'[&type=leaf,weight=1,supertree_leaf=1],'Rhea americana'[&type=leaf,weight=1,supertree_leaf=1])Rhea[&type=internal,weight=6],('Pterocnemia darwinii'[&type=leaf,weight=1,supertree_leaf=1],'Pterocnemia pennata'[&type=leaf,weight=1,supertree_leaf=1])Pterocnemia[&type=internal,weight=2],('Protorhea azarae'[&type=leaf,weight=1,supertree_leaf=1])Protorhea[&type=internal,weight=1])Rheidae[&type=internal,weight=9])Rheiformes[&type=internal,weight=9],(((('Casuarius casuarius'[&type=leaf,weight=5],other_Casuarius[&type=other,count=14,weight=14],'Casuarius unappendiculatus'[&type=leaf,weight=4])Casuarius[&type=internal,weight=23])Casuariidae[&type=internal,weight=23],(('Dromaius ater'[&type=leaf,weight=1,supertree_leaf=1],'Dromaius novaehollandiae'[&type=leaf,weight=1,supertree_leaf=1])Dromaius[&type=internal,weight=2])Dromaiidae[&type=internal,weight=2])Casuariiformes[&type=internal,weight=25],((((((mrcaott165688ott748998[&type=leaf,weight=2],'Apteryx australis australis'[&type=leaf,weight=1,supertree_leaf=1])mrcaott165688ott412972[&type=internal,weight=3],'Apteryx haastii'[&type=leaf,weight=1,supertree_leaf=1])mrcaott165688ott241836[&type=internal,weight=4],'Apteryx owenii'[&type=leaf,weight=1,supertree_leaf=1])Apteryx[&type=internal,weight=5])Apterygidae[&type=internal,weight=5])Apterygiformes[&type=internal,weight=5],(('Aepyornis hildebrandti'[&type=leaf,weight=1,supertree_leaf=1])Aepyornis[&type=internal,weight=1],('Mullerornis agilis'[&type=leaf,weight=1,supertree_leaf=1])Mullerornis[&type=internal,weight=1])Aepyornithidae[&type=internal,weight=2])mrcaott84218ott165688[&type=internal,weight=7])mrcaott84218ott402459[&type=internal,weight=32])mrcaott84218ott857860[&type=internal,weight=41];",
"((('Casuarius casuarius'[&type=leaf,weight=5],('Casuarius unappendiculatus rufotinctus'[&type=leaf,weight=1,supertree_leaf=1],'other_Casuarius unappendiculatus'[&type=other,count=2,weight=2],'Casuarius unappendiculatus rothschildi'[&type=leaf,weight=1,supertree_leaf=1])'Casuarius unappendiculatus'[&type=internal,weight=4],'Casuarius roseigularis'[&type=leaf,weight=1,supertree_leaf=1],'Casuarius rogersi'[&type=leaf,weight=1,supertree_leaf=1],'Casuarius picticollis'[&type=leaf,weight=1,supertree_leaf=1],'Casuarius philipi'[&type=leaf,weight=1,supertree_leaf=1],'Casuarius loriae'[&type=leaf,weight=1,supertree_leaf=1],'Casuarius keysseri'[&type=leaf,weight=1,supertree_leaf=1],'Casuarius jamrachi'[&type=leaf,weight=1,supertree_leaf=1],'Casuarius hagenbecki'[&type=leaf,weight=1,supertree_leaf=1],'Casuarius foersteri'[&type=leaf,weight=1,supertree_leaf=1],'Casuarius doggetti'[&type=leaf,weight=1,supertree_leaf=1],'Casuarius claudii'[&type=leaf,weight=1,supertree_leaf=1],'Casuarius bicarunculatus'[&type=leaf,weight=1,supertree_leaf=1],'Casuarius altijugus'[&type=leaf,weight=1,supertree_leaf=1],'Casuarius bennetti'[&type=leaf,weight=1,supertree_leaf=1])Casuarius[&type=internal,weight=23])Casuariidae[&type=internal,weight=23],(('Dromaius ater'[&type=leaf,weight=1,supertree_leaf=1],'Dromaius novaehollandiae'[&type=leaf,weight=1,supertree_leaf=1])Dromaius[&type=internal,weight=2])Dromaiidae[&type=internal,weight=2])Casuariiformes[&type=internal,weight=25];",

];

$newick_strings = [
//"((((((((((((((((mrcaott31017ott134468[&type=leaf,weight=9],other_mrcaott31017ott341459[&type=other,count=2,weight=2])mrcaott31017ott341459[&type=internal,weight=11],mrcaott31011ott31021[&type=leaf,weight=8])mrcaott31011ott31017[&type=internal,weight=19],mrcaott457331ott471659[&type=leaf,weight=2])mrcaott31011ott457331[&type=internal,weight=21],'Puffinus subalaris'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31011ott501951[&type=internal,weight=22],other_Puffinus[&type=other,count=26,weight=26])Puffinus[&type=internal,weight=48],Ardenna[&type=leaf,weight=7])mrcaott31011ott471652[&type=internal,weight=55],Calonectris[&type=leaf,weight=5])mrcaott31011ott82215[&type=internal,weight=60],Procellaria[&type=leaf,weight=13])mrcaott31011ott379429[&type=internal,weight=73],mrcaott172670ott944663[&type=leaf,weight=8])mrcaott31011ott172670[&type=internal,weight=81],(((((mrcaott73703ott271044[&type=leaf,weight=10],'Pterodroma inexpectata'[&type=leaf,weight=1,supertree_leaf=1])mrcaott73703ott85282[&type=internal,weight=11],'Pterodroma axillaris'[&type=leaf,weight=1,supertree_leaf=1])mrcaott73703ott845409[&type=internal,weight=12],mrcaott319464ott485716[&type=leaf,weight=2])mrcaott73703ott319464[&type=internal,weight=14],mrcaott271031ott845415[&type=leaf,weight=5])mrcaott73703ott271031[&type=internal,weight=19],other_Pterodroma[&type=other,count=10,weight=10],((mrcaott271029ott271036[&type=leaf,weight=10],'Pterodroma mollis'[&type=leaf,weight=2])mrcaott271029ott271043[&type=internal,weight=12],'Pterodroma hypoleuca'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271029ott797520[&type=internal,weight=13])Pterodroma[&type=internal,weight=42])mrcaott31011ott73703[&type=internal,weight=123],(((Pachyptila[&type=leaf,weight=9],Halobaena[&type=leaf,weight=3])mrcaott85289ott656582[&type=internal,weight=12],Pelecanoididae[&type=leaf,weight=7])mrcaott85289ott603893[&type=internal,weight=19],Aphrodroma[&type=leaf,weight=1])mrcaott85271ott85289[&type=internal,weight=20])mrcaott31011ott85271[&type=internal,weight=143],((mrcaott82196ott603906[&type=leaf,weight=8],Daption[&type=leaf,weight=2])mrcaott82196ott726152[&type=internal,weight=10],mrcaott656580ott944660[&type=leaf,weight=2])mrcaott82196ott656580[&type=internal,weight=12])mrcaott31011ott82196[&type=internal,weight=155],((mrcaott65050ott603904[&type=leaf,weight=12],(mrcaott406716ott3595642[&type=leaf,weight=8],'Hydrobates homochroa'[&type=leaf,weight=1,supertree_leaf=1])mrcaott406716ott1021954[&type=internal,weight=9])Hydrobates[&type=internal,weight=21],Oceanodroma[&type=leaf,weight=2])mrcaott65050ott3595638[&type=internal,weight=23])mrcaott31011ott65050[&type=internal,weight=178],((mrcaott18209ott49107[&type=leaf,weight=11],mrcaott134492ott1019090[&type=leaf,weight=6])mrcaott18209ott134492[&type=internal,weight=17],other_mrcaott18206ott18209[&type=other,count=3,weight=4])mrcaott18206ott18209[&type=internal,weight=21],Thalassidroma[&type=leaf,weight=1])mrcaott18206ott31011[&type=internal,weight=200],((Diomedea[&type=leaf,weight=11],Phoebastria[&type=leaf,weight=5])mrcaott71459ott320282[&type=internal,weight=16],((mrcaott134477ott320280[&type=leaf,weight=9],mrcaott134478ott320276[&type=leaf,weight=2])Thalassarche[&type=internal,weight=11],Phoebetria[&type=leaf,weight=2])mrcaott134477ott592673[&type=internal,weight=13],Plotornis[&type=leaf,weight=2])Diomedeidae[&type=internal,weight=31])Procellariiformes[&type=internal,weight=231])mrcaott18206ott60413;",
//"(((((((((((mrcaott319472ott449678[&type=leaf,weight=2],'Pterodroma phaeopygia'[&type=leaf,weight=1,supertree_leaf=1])mrcaott319472ott485717[&type=internal,weight=3],'Pterodroma externa'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271035ott319472[&type=internal,weight=4],'Pterodroma cervicalis'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271035ott461783[&type=internal,weight=5],'Pterodroma ultima'[&type=leaf,weight=1,supertree_leaf=1])mrcaott73703ott271035[&type=internal,weight=6],((mrcaott285635ott461784[&type=leaf,weight=2],'Pterodroma arminjoniana'[&type=leaf,weight=1,supertree_leaf=1])mrcaott285635ott666330[&type=internal,weight=3],'Pterodroma neglecta'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271044ott285635[&type=internal,weight=4])mrcaott73703ott271044[&type=internal,weight=10],'Pterodroma inexpectata'[&type=leaf,weight=1,supertree_leaf=1])mrcaott73703ott85282[&type=internal,weight=11],'Pterodroma axillaris'[&type=leaf,weight=1,supertree_leaf=1])mrcaott73703ott845409[&type=internal,weight=12],mrcaott319464ott485716[&type=leaf,weight=2])mrcaott73703ott319464[&type=internal,weight=14],(((mrcaott3595611ott4947457[&type=leaf,weight=2],'Pterodroma pycrofti'[&type=leaf,weight=1,supertree_leaf=1])mrcaott3595611ott3595620[&type=internal,weight=3],'Pterodroma cookii'[&type=leaf,weight=1,supertree_leaf=1])mrcaott845415ott3595611[&type=internal,weight=4],'Pterodroma longirostris'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271031ott845415[&type=internal,weight=5])mrcaott73703ott271031[&type=internal,weight=19],other_Pterodroma[&type=other,count=6,weight=6],((((((mrcaott713614ott950182[&type=leaf,weight=2],'Pterodroma cahow'[&type=leaf,weight=1,supertree_leaf=1])mrcaott713614ott845408[&type=internal,weight=3],'Pterodroma hasitata'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271036ott713614[&type=internal,weight=4],'Pterodroma imberi'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271036ott5925783[&type=internal,weight=5],((('Pterodroma macroptera'[&type=leaf,weight=2],'Pterodroma lessonii'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271034ott581903[&type=internal,weight=3],'Pterodroma incerta'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271033ott271034[&type=internal,weight=4],'Pterodroma magentae'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271029ott271033[&type=internal,weight=5])mrcaott271029ott271036[&type=internal,weight=10],'Pterodroma mollis'[&type=leaf,weight=2])mrcaott271029ott271043[&type=internal,weight=12],'Pterodroma hypoleuca'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271029ott797520[&type=internal,weight=13],'Pterodroma wortheni'[&type=leaf,weight=1,supertree_leaf=1],'Pterodroma oliveri'[&type=leaf,weight=1,supertree_leaf=1],'Pterodroma deceptornis'[&type=leaf,weight=1,supertree_leaf=1],'Pterodroma chionophara'[&type=leaf,weight=1,supertree_leaf=1])Pterodroma[&type=internal,weight=42])mrcaott31011ott73703;",
//"((((((((((mrcaott828823ott5860910[&type=leaf,weight=2],'Puffinus myrtae'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31017ott828823[&type=internal,weight=3],'Puffinus bannermani'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31017ott4947459[&type=internal,weight=4],('Puffinus bailloni'[&type=leaf,weight=2],'Puffinus persicus'[&type=leaf,weight=1,supertree_leaf=1])mrcaott828827ott7068757[&type=internal,weight=3])mrcaott31017ott828827[&type=internal,weight=7],mrcaott134468ott351968[&type=leaf,weight=2])mrcaott31017ott134468[&type=internal,weight=9],other_mrcaott31017ott341459[&type=other,count=2,weight=2])mrcaott31017ott341459[&type=internal,weight=11],((mrcaott31021ott471650[&type=leaf,weight=3],mrcaott471651ott471658[&type=leaf,weight=2])mrcaott31021ott471651[&type=internal,weight=5],(mrcaott31013ott946840[&type=leaf,weight=2],'Puffinus loyemilleri'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31011ott31013[&type=internal,weight=3])mrcaott31011ott31021[&type=internal,weight=8])mrcaott31011ott31017[&type=internal,weight=19],mrcaott457331ott471659[&type=leaf,weight=2])mrcaott31011ott457331[&type=internal,weight=21],'Puffinus subalaris'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31011ott501951[&type=internal,weight=22],other_Puffinus[&type=other,count=10,weight=10],'Puffinus parvus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus mariae'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus cuneatus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus chlororhynchus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus micraulax'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus inceptor'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus diatomicus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus calhouni'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus barnesi'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus heinrothi'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus mcgalli'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus priscus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus gilmorei'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus raemdonckii'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus reinholdi'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus mitchelli'[&type=leaf,weight=1,supertree_leaf=1])Puffinus[&type=internal,weight=48])mrcaott31011ott471652;"
//"((((((((((mrcaott828823ott5860910[&type=leaf,weight=2],'Puffinus myrtae'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31017ott828823[&type=internal,weight=3],'Puffinus bannermani'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31017ott4947459[&type=internal,weight=4],('Puffinus bailloni'[&type=leaf,weight=2],'Puffinus persicus'[&type=leaf,weight=1,supertree_leaf=1])mrcaott828827ott7068757[&type=internal,weight=3])mrcaott31017ott828827[&type=internal,weight=7],mrcaott134468ott351968[&type=leaf,weight=2])mrcaott31017ott134468[&type=internal,weight=9],other_mrcaott31017ott341459[&type=other,count=2,weight=2])mrcaott31017ott341459[&type=internal,weight=11],((mrcaott31021ott471650[&type=leaf,weight=3],mrcaott471651ott471658[&type=leaf,weight=2])mrcaott31021ott471651[&type=internal,weight=5],(mrcaott31013ott946840[&type=leaf,weight=2],'Puffinus loyemilleri'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31011ott31013[&type=internal,weight=3])mrcaott31011ott31021[&type=internal,weight=8])mrcaott31011ott31017[&type=internal,weight=19],('Puffinus gavia'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus huttoni'[&type=leaf,weight=1,supertree_leaf=1])mrcaott457331ott471659[&type=internal,weight=2])mrcaott31011ott457331[&type=internal,weight=21],'Puffinus subalaris'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31011ott501951[&type=internal,weight=22],'Puffinus parvus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus mariae'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus cuneatus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus chlororhynchus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus micraulax'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus inceptor'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus diatomicus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus calhouni'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus barnesi'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus heinrothi'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus mcgalli'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus priscus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus gilmorei'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus raemdonckii'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus reinholdi'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus mitchelli'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus dichrous'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus nicolae'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus polynesiae'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus haurakiensis'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus colstoni'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus atrodorsalis'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus nativitatis'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus tunneyi'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus temptator'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus kermadecensis'[&type=leaf,weight=1,supertree_leaf=1])Puffinus[&type=internal,weight=48])mrcaott31011ott471652;",
//"(((((((((((mrcaott828823ott5860910[&type=leaf,weight=2],'Puffinus myrtae'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31017ott828823[&type=internal,weight=3],'Puffinus bannermani'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31017ott4947459[&type=internal,weight=4],('Puffinus bailloni'[&type=leaf,weight=2],'Puffinus persicus'[&type=leaf,weight=1,supertree_leaf=1])mrcaott828827ott7068757[&type=internal,weight=3])mrcaott31017ott828827[&type=internal,weight=7],mrcaott134468ott351968[&type=leaf,weight=2])mrcaott31017ott134468[&type=internal,weight=9],other_mrcaott31017ott341459[&type=other,count=2,weight=2])mrcaott31017ott341459[&type=internal,weight=11],((mrcaott31021ott471650[&type=leaf,weight=3],mrcaott471651ott471658[&type=leaf,weight=2])mrcaott31021ott471651[&type=internal,weight=5],(mrcaott31013ott946840[&type=leaf,weight=2],'Puffinus loyemilleri'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31011ott31013[&type=internal,weight=3])mrcaott31011ott31021[&type=internal,weight=8])mrcaott31011ott31017[&type=internal,weight=19],mrcaott457331ott471659[&type=leaf,weight=2])mrcaott31011ott457331[&type=internal,weight=21],'Puffinus subalaris'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31011ott501951[&type=internal,weight=22],other_Puffinus[&type=other,count=5,weight=5],'Puffinus parvus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus mariae'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus cuneatus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus chlororhynchus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus micraulax'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus inceptor'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus diatomicus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus calhouni'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus barnesi'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus heinrothi'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus mcgalli'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus priscus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus gilmorei'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus raemdonckii'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus reinholdi'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus mitchelli'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus dichrous'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus nicolae'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus polynesiae'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus haurakiensis'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus colstoni'[&type=leaf,weight=1,supertree_leaf=1])Puffinus[&type=internal,weight=48],((((mrcaott946833ott946834[&type=leaf,weight=2],'Ardenna gravis'[&type=leaf,weight=1,supertree_leaf=1])mrcaott726165ott946833[&type=internal,weight=3],'Ardenna grisea'[&type=leaf,weight=1,supertree_leaf=1])mrcaott726165ott726166[&type=internal,weight=4],'Ardenna tenuirostris'[&type=leaf,weight=1,supertree_leaf=1])mrcaott471652ott726165[&type=internal,weight=5],mrcaott471653ott946835[&type=leaf,weight=2])Ardenna[&type=internal,weight=7])mrcaott31011ott471652[&type=internal,weight=55])mrcaott31011ott82215;",

//"(((('Phoebastria nihonus'[&type=leaf,weight=1,supertree_leaf=1],('Phoebastria nigripes'[&type=leaf,weight=1,supertree_leaf=1],'Phoebastria immutabilis'[&type=leaf,weight=1,supertree_leaf=1])mrcaott320286ott320288[&type=internal,weight=2],('Phoebastria albatrus'[&type=leaf,weight=1,supertree_leaf=1],'Phoebastria irrorata'[&type=leaf,weight=1,supertree_leaf=1])mrcaott320282ott320284[&type=internal,weight=2])Phoebastria[&type=internal,weight=5],('Diomedea spadicea'[&type=leaf,weight=1,supertree_leaf=1],'Diomedea chlororhynchus'[&type=leaf,weight=1,supertree_leaf=1],'Diomedea sanfordi'[&type=leaf,weight=1,supertree_leaf=1],'Diomedea leptorhyncha'[&type=leaf,weight=1,supertree_leaf=1],('Diomedea epomophora sanfordi'[&type=leaf,weight=1,supertree_leaf=1])'Diomedea epomophora'[&type=internal,weight=1],'Diomedea chionoptera'[&type=leaf,weight=1,supertree_leaf=1],'Diomedea gibsoni'[&type=leaf,weight=1,supertree_leaf=1],(('Diomedea dabbenena'[&type=leaf,weight=1,supertree_leaf=1],'Diomedea antipodensis'[&type=leaf,weight=1,supertree_leaf=1])mrcaott82214ott300867[&type=internal,weight=2],'Diomedea amsterdamensis'[&type=leaf,weight=1,supertree_leaf=1])mrcaott71463ott82214[&type=internal,weight=3],'Diomedea exulans'[&type=leaf,weight=1,supertree_leaf=1])Diomedea[&type=internal,weight=11])mrcaott71459ott320282[&type=internal,weight=16],(((((('Thalassarche cauta'[&type=leaf,weight=1],'Thalassarche steadi'[&type=leaf,weight=1,supertree_leaf=1])mrcaott250508ott320292[&type=internal,weight=2],mrcaott134477ott514671[&type=leaf,weight=2])mrcaott134477ott250508[&type=internal,weight=4],('Thalassarche bulleri platei'[&type=leaf,weight=1,supertree_leaf=1],'Thalassarche bulleri bulleri'[&type=leaf,weight=1,supertree_leaf=1])'Thalassarche bulleri'[&type=internal,weight=2])mrcaott134477ott592670[&type=internal,weight=6],(('Thalassarche impavida'[&type=leaf,weight=1,supertree_leaf=1],('Thalassarche melanophrys melanophrys'[&type=leaf,weight=1,supertree_leaf=1])'Thalassarche melanophrys'[&type=internal,weight=1])mrcaott320294ott514681[&type=internal,weight=2],'Thalassarche chrysostoma'[&type=leaf,weight=1,supertree_leaf=1])mrcaott320280ott320294[&type=internal,weight=3])mrcaott134477ott320280[&type=internal,weight=9],(('Thalassarche chlororhynchos chlororhynchos'[&type=leaf,weight=1,supertree_leaf=1])'Thalassarche chlororhynchos'[&type=internal,weight=1],'Thalassarche carteri'[&type=leaf,weight=1,supertree_leaf=1])mrcaott134478ott320276[&type=internal,weight=2])Thalassarche[&type=internal,weight=11],('Phoebetria palpebrata'[&type=leaf,weight=1,supertree_leaf=1],'Phoebetria fusca'[&type=leaf,weight=1,supertree_leaf=1])Phoebetria[&type=internal,weight=2])mrcaott134477ott592673[&type=internal,weight=13],('Plotornis graculoides'[&type=leaf,weight=1,supertree_leaf=1],'Plotornis delfortrii'[&type=leaf,weight=1,supertree_leaf=1])Plotornis[&type=internal,weight=2])Diomedeidae[&type=internal,weight=31])Procellariiformes;",


//"(((((mrcaott18209ott49107[&type=leaf,weight=11],mrcaott134492ott1019090[&type=leaf,weight=6])mrcaott18209ott134492[&type=internal,weight=17],other_mrcaott18206ott18209[&type=other,count=3,weight=4])mrcaott18206ott18209[&type=internal,weight=21],(((((((((((mrcaott31011ott31017[&type=leaf,weight=19],mrcaott457331ott471659[&type=leaf,weight=2])mrcaott31011ott457331[&type=internal,weight=21],'Puffinus subalaris'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31011ott501951[&type=internal,weight=22],other_Puffinus[&type=other,count=26,weight=26])Puffinus[&type=internal,weight=48],Ardenna[&type=leaf,weight=7])mrcaott31011ott471652[&type=internal,weight=55],Calonectris[&type=leaf,weight=5])mrcaott31011ott82215[&type=internal,weight=60],Procellaria[&type=leaf,weight=13])mrcaott31011ott379429[&type=internal,weight=73],mrcaott172670ott944663[&type=leaf,weight=8])mrcaott31011ott172670[&type=internal,weight=81],(((mrcaott73703ott845409[&type=leaf,weight=12],mrcaott319464ott485716[&type=leaf,weight=2])mrcaott73703ott319464[&type=internal,weight=14],mrcaott271031ott845415[&type=leaf,weight=5])mrcaott73703ott271031[&type=internal,weight=19],(mrcaott271029ott271043[&type=leaf,weight=12],'Pterodroma hypoleuca'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271029ott797520[&type=internal,weight=13],other_Pterodroma[&type=other,count=10,weight=10])Pterodroma[&type=internal,weight=42])mrcaott31011ott73703[&type=internal,weight=123],(Aphrodroma[&type=leaf,weight=1],(mrcaott85289ott656582[&type=leaf,weight=12],Pelecanoididae[&type=leaf,weight=7])mrcaott85289ott603893[&type=internal,weight=19])mrcaott85271ott85289[&type=internal,weight=20])mrcaott31011ott85271[&type=internal,weight=143],mrcaott82196ott656580[&type=leaf,weight=12])mrcaott31011ott82196[&type=internal,weight=155],((mrcaott65050ott603904[&type=leaf,weight=12],mrcaott406716ott1021954[&type=leaf,weight=9])Hydrobates[&type=internal,weight=21],Oceanodroma[&type=leaf,weight=2])mrcaott65050ott3595638[&type=internal,weight=23])mrcaott31011ott65050[&type=internal,weight=178],Thalassidroma[&type=leaf,weight=1])mrcaott18206ott31011[&type=internal,weight=200],((Diomedea[&type=leaf,weight=11],Phoebastria[&type=leaf,weight=5])mrcaott71459ott320282[&type=internal,weight=16],(Thalassarche[&type=leaf,weight=11],Phoebetria[&type=leaf,weight=2])mrcaott134477ott592673[&type=internal,weight=13],Plotornis[&type=leaf,weight=2])Diomedeidae[&type=internal,weight=31])Procellariiformes[&type=internal,weight=231])mrcaott18206ott60413;",
//"(((('Oceanites gracilis gracilis'[&type=leaf,weight=1,supertree_leaf=1],'Oceanites gracilis galapagoensis'[&type=leaf,weight=1,supertree_leaf=1])'Oceanites gracilis'[&type=internal,weight=2],other_mrcaott18206ott18209[&type=other,count=2,weight=2],(mrcaott18209ott49107[&type=leaf,weight=11],mrcaott134492ott1019090[&type=leaf,weight=6])mrcaott18209ott134492[&type=internal,weight=17])mrcaott18206ott18209[&type=internal,weight=21],((((((((((((mrcaott31017ott341459[&type=leaf,weight=11],mrcaott31011ott31021[&type=leaf,weight=8])mrcaott31011ott31017[&type=internal,weight=19],mrcaott457331ott471659[&type=leaf,weight=2])mrcaott31011ott457331[&type=internal,weight=21],'Puffinus subalaris'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31011ott501951[&type=internal,weight=22],other_Puffinus[&type=other,count=26,weight=26])Puffinus[&type=internal,weight=48],Ardenna[&type=leaf,weight=7])mrcaott31011ott471652[&type=internal,weight=55],Calonectris[&type=leaf,weight=5])mrcaott31011ott82215[&type=internal,weight=60],Procellaria[&type=leaf,weight=13])mrcaott31011ott379429[&type=internal,weight=73],mrcaott172670ott944663[&type=leaf,weight=8])mrcaott31011ott172670[&type=internal,weight=81],((((mrcaott73703ott85282[&type=leaf,weight=11],'Pterodroma axillaris'[&type=leaf,weight=1,supertree_leaf=1])mrcaott73703ott845409[&type=internal,weight=12],mrcaott319464ott485716[&type=leaf,weight=2])mrcaott73703ott319464[&type=internal,weight=14],mrcaott271031ott845415[&type=leaf,weight=5])mrcaott73703ott271031[&type=internal,weight=19],other_Pterodroma[&type=other,count=10,weight=10],(mrcaott271029ott271043[&type=leaf,weight=12],'Pterodroma hypoleuca'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271029ott797520[&type=internal,weight=13])Pterodroma[&type=internal,weight=42])mrcaott31011ott73703[&type=internal,weight=123],((mrcaott85289ott656582[&type=leaf,weight=12],Pelecanoididae[&type=leaf,weight=7])mrcaott85289ott603893[&type=internal,weight=19],Aphrodroma[&type=leaf,weight=1])mrcaott85271ott85289[&type=internal,weight=20])mrcaott31011ott85271[&type=internal,weight=143],(mrcaott82196ott726152[&type=leaf,weight=10],mrcaott656580ott944660[&type=leaf,weight=2])mrcaott82196ott656580[&type=internal,weight=12])mrcaott31011ott82196[&type=internal,weight=155],((mrcaott65050ott603904[&type=leaf,weight=12],mrcaott406716ott1021954[&type=leaf,weight=9])Hydrobates[&type=internal,weight=21],Oceanodroma[&type=leaf,weight=2])mrcaott65050ott3595638[&type=internal,weight=23])mrcaott31011ott65050[&type=internal,weight=178],Thalassidroma[&type=leaf,weight=1])mrcaott18206ott31011[&type=internal,weight=200])Procellariiformes;",

//"(((((((('Puffinus loyemilleri'[&type=leaf,weight=1,supertree_leaf=1],mrcaott31013ott946840[&type=leaf,weight=2])mrcaott31011ott31013[&type=internal,weight=3],(mrcaott31021ott471650[&type=leaf,weight=3],mrcaott471651ott471658[&type=leaf,weight=2])mrcaott31021ott471651[&type=internal,weight=5])mrcaott31011ott31021[&type=internal,weight=8],((((('Puffinus myrtae'[&type=leaf,weight=1,supertree_leaf=1],mrcaott828823ott5860910[&type=leaf,weight=2])mrcaott31017ott828823[&type=internal,weight=3],'Puffinus bannermani'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31017ott4947459[&type=internal,weight=4],('Puffinus persicus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus bailloni'[&type=leaf,weight=2])mrcaott828827ott7068757[&type=internal,weight=3])mrcaott31017ott828827[&type=internal,weight=7],mrcaott134468ott351968[&type=leaf,weight=2])mrcaott31017ott134468[&type=internal,weight=9],other_mrcaott31017ott341459[&type=other,count=2,weight=2])mrcaott31017ott341459[&type=internal,weight=11])mrcaott31011ott31017[&type=internal,weight=19],mrcaott457331ott471659[&type=leaf,weight=2])mrcaott31011ott457331[&type=internal,weight=21],'Puffinus subalaris'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31011ott501951[&type=internal,weight=22],'Puffinus mcgalli'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus heinrothi'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus barnesi'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus calhouni'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus diatomicus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus inceptor'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus micraulax'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus chlororhynchus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus cuneatus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus mariae'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus parvus'[&type=leaf,weight=1,supertree_leaf=1],other_Puffinus[&type=other,count=15,weight=15])Puffinus[&type=internal,weight=48],(('Ardenna tenuirostris'[&type=leaf,weight=1,supertree_leaf=1],(('Ardenna gravis'[&type=leaf,weight=1,supertree_leaf=1],mrcaott946833ott946834[&type=leaf,weight=2])mrcaott726165ott946833[&type=internal,weight=3],'Ardenna grisea'[&type=leaf,weight=1,supertree_leaf=1])mrcaott726165ott726166[&type=internal,weight=4])mrcaott471652ott726165[&type=internal,weight=5],mrcaott471653ott946835[&type=leaf,weight=2])Ardenna[&type=internal,weight=7])mrcaott31011ott471652[&type=internal,weight=55])mrcaott31011ott82215;",
"((((('Fregetta grallaria titan + Oceanites maorianus'[&type=leaf,weight=11],'Garrodia nereis + Oceanites oceanicus exasperatus'[&type=leaf,weight=6])'Fregetta grallaria titan + Garrodia nereis'[&type=internal,weight=17],'other_Oceanites gracilis galapagoensis + Fregetta grallaria titan'[&type=other,count=3,weight=4])'Oceanites gracilis galapagoensis + Fregetta grallaria titan'[&type=internal,weight=21],((((((((((('Puffinus loyemilleri + Puffinus myrtae'[&type=leaf,weight=19],'Puffinus huttoni + Puffinus gavia'[&type=leaf,weight=2])'Puffinus loyemilleri + Puffinus huttoni'[&type=internal,weight=21],'Puffinus subalaris'[&type=leaf,weight=1,supertree_leaf=1])'Puffinus loyemilleri + Puffinus subalaris'[&type=internal,weight=22],other_Puffinus[&type=other,count=26,weight=26])Puffinus[&type=internal,weight=48],Ardenna[&type=leaf,weight=7])'Puffinus loyemilleri + Ardenna tenuirostris'[&type=internal,weight=55],Calonectris[&type=leaf,weight=5])'Puffinus loyemilleri + Calonectris leucomelas'[&type=internal,weight=60],Procellaria[&type=leaf,weight=13])'Puffinus loyemilleri + Procellaria westlandica'[&type=internal,weight=73],'Pseudobulweria aterrima + Bulweria bulwerii'[&type=leaf,weight=8])'Puffinus loyemilleri + Pseudobulweria aterrima'[&type=internal,weight=81],((('Pterodroma ultima + Pterodroma axillaris'[&type=leaf,weight=12],'Pterodroma nigripennis + Pterodroma solandri'[&type=leaf,weight=2])'Pterodroma ultima + Pterodroma nigripennis'[&type=internal,weight=14],'Pterodroma longirostris + Pterodroma cookii'[&type=leaf,weight=5])'Pterodroma ultima + Pterodroma longirostris'[&type=internal,weight=19],('Pterodroma magentae + Pterodroma mollis mollis'[&type=leaf,weight=12],'Pterodroma hypoleuca'[&type=leaf,weight=1,supertree_leaf=1])'Pterodroma magentae + Pterodroma hypoleuca'[&type=internal,weight=13],other_Pterodroma[&type=other,count=10,weight=10])Pterodroma[&type=internal,weight=42])'Puffinus loyemilleri + Pterodroma ultima'[&type=internal,weight=123],(Aphrodroma[&type=leaf,weight=1],('Pachyptila vittata + Halobaena caerulea'[&type=leaf,weight=12],Pelecanoididae[&type=leaf,weight=7])'Pachyptila vittata + Pelecanoides magellani'[&type=internal,weight=19])'Aphrodroma brevirostris + Pachyptila vittata'[&type=internal,weight=20])'Puffinus loyemilleri + Aphrodroma brevirostris'[&type=internal,weight=143],'Fulmarus glacialoides + Thalassoica antarctica'[&type=leaf,weight=12])'Puffinus loyemilleri + Fulmarus glacialoides'[&type=internal,weight=155],(('Hydrobates monteiroi + Hydrobates melania'[&type=leaf,weight=12],'Hydrobates monorhis + Hydrobates homochroa'[&type=leaf,weight=9])Hydrobates[&type=internal,weight=21],Oceanodroma[&type=leaf,weight=2])'Hydrobates monteiroi + Oceanodroma macrodactyla'[&type=internal,weight=23])'Puffinus loyemilleri + Hydrobates monteiroi'[&type=internal,weight=178],Thalassidroma[&type=leaf,weight=1])'Oceanites gracilis galapagoensis + Puffinus loyemilleri'[&type=internal,weight=200],((Diomedea[&type=leaf,weight=11],Phoebastria[&type=leaf,weight=5])'Diomedea exulans + Phoebastria irrorata'[&type=internal,weight=16],(Thalassarche[&type=leaf,weight=11],Phoebetria[&type=leaf,weight=2])'Thalassarche eremita + Phoebetria fusca'[&type=internal,weight=13],Plotornis[&type=leaf,weight=2])Diomedeidae[&type=internal,weight=31])Procellariiformes[&type=internal,weight=231])'Oceanites gracilis galapagoensis + Eudyptes sclateri';",
"(((((('Fregetta grallaria titan'[&type=leaf,weight=1,supertree_leaf=1],'Fregetta grallaria leucogaster'[&type=leaf,weight=1,supertree_leaf=1],'Fregetta grallaria segethi'[&type=leaf,weight=1,supertree_leaf=1],'Fregetta grallaria grallaria'[&type=leaf,weight=1,supertree_leaf=1])'Fregetta grallaria'[&type=internal,weight=4],('Oceanites maorianus'[&type=leaf,weight=1,supertree_leaf=1],('Fregetta tropica melanoleuca'[&type=leaf,weight=1,supertree_leaf=1],'Fregetta tropica tropica'[&type=leaf,weight=1,supertree_leaf=1])'Fregetta tropica'[&type=internal,weight=2])'Oceanites maorianus + Fregetta tropica melanoleuca'[&type=internal,weight=3],'Fregetta lineata'[&type=leaf,weight=1,supertree_leaf=1],'Fregetta moestissima'[&type=leaf,weight=1,supertree_leaf=1],'Fregetta royana'[&type=leaf,weight=1,supertree_leaf=1],'Fregetta tubulata'[&type=leaf,weight=1,supertree_leaf=1])'Fregetta grallaria titan + Oceanites maorianus'[&type=internal,weight=11],'Garrodia nereis + Oceanites oceanicus exasperatus'[&type=leaf,weight=6])'Fregetta grallaria titan + Garrodia nereis'[&type=internal,weight=17],'other_Oceanites gracilis galapagoensis + Fregetta grallaria titan'[&type=other,count=3,weight=4])'Oceanites gracilis galapagoensis + Fregetta grallaria titan'[&type=internal,weight=21],((((((((((('Puffinus loyemilleri + Puffinus myrtae'[&type=leaf,weight=19],'Puffinus huttoni + Puffinus gavia'[&type=leaf,weight=2])'Puffinus loyemilleri + Puffinus huttoni'[&type=internal,weight=21],'Puffinus subalaris'[&type=leaf,weight=1,supertree_leaf=1])'Puffinus loyemilleri + Puffinus subalaris'[&type=internal,weight=22],other_Puffinus[&type=other,count=26,weight=26])Puffinus[&type=internal,weight=48],Ardenna[&type=leaf,weight=7])'Puffinus loyemilleri + Ardenna tenuirostris'[&type=internal,weight=55],Calonectris[&type=leaf,weight=5])'Puffinus loyemilleri + Calonectris leucomelas'[&type=internal,weight=60],Procellaria[&type=leaf,weight=13])'Puffinus loyemilleri + Procellaria westlandica'[&type=internal,weight=73],'Pseudobulweria aterrima + Bulweria bulwerii'[&type=leaf,weight=8])'Puffinus loyemilleri + Pseudobulweria aterrima'[&type=internal,weight=81],(('Pterodroma ultima + Pterodroma nigripennis'[&type=leaf,weight=14],'Pterodroma longirostris + Pterodroma cookii'[&type=leaf,weight=5])'Pterodroma ultima + Pterodroma longirostris'[&type=internal,weight=19],other_Pterodroma[&type=other,count=11,weight=23])Pterodroma[&type=internal,weight=42])'Puffinus loyemilleri + Pterodroma ultima'[&type=internal,weight=123],(Aphrodroma[&type=leaf,weight=1],'Pachyptila vittata + Pelecanoides magellani'[&type=leaf,weight=19])'Aphrodroma brevirostris + Pachyptila vittata'[&type=internal,weight=20])'Puffinus loyemilleri + Aphrodroma brevirostris'[&type=internal,weight=143],'Fulmarus glacialoides + Thalassoica antarctica'[&type=leaf,weight=12])'Puffinus loyemilleri + Fulmarus glacialoides'[&type=internal,weight=155],(Hydrobates[&type=leaf,weight=21],Oceanodroma[&type=leaf,weight=2])'Hydrobates monteiroi + Oceanodroma macrodactyla'[&type=internal,weight=23])'Puffinus loyemilleri + Hydrobates monteiroi'[&type=internal,weight=178],Thalassidroma[&type=leaf,weight=1])'Oceanites gracilis galapagoensis + Puffinus loyemilleri'[&type=internal,weight=200])Procellariiformes;",

];

/*
$newick_strings = [
'((a,b)x,c)z;',
'(((a,b)x,c)z)w;',
];
*/

/*
$newick_strings = [
"(((((((((((mrcaott22659ott105463[&type=leaf,weight=1469],other_Leptoceridae[&type=other,count=13,weight=197])Leptoceridae[&type=internal,weight=1666],Atriplectididae[&type=leaf,weight=6])mrcaott869ott183532[&type=internal,weight=1672],mrcaott80475ott183538[&type=leaf,weight=259])mrcaott869ott80475[&type=internal,weight=1931],mrcaott16921ott193121[&type=leaf,weight=155])mrcaott869ott16921[&type=internal,weight=2086],Sericostomatoidea[&type=leaf,weight=606])mrcaott869ott16051[&type=internal,weight=2692],mrcaott171609ott335267[&type=leaf,weight=28])mrcaott869ott171609[&type=internal,weight=2720],(((((((mrcaott15023ott293831[&type=leaf,weight=1256],other_mrcaott15023ott80463[&type=other,count=2,weight=47])mrcaott15023ott80463[&type=internal,weight=1303],other_mrcaott15023ott38014[&type=other,count=10,weight=175])mrcaott15023ott38014[&type=internal,weight=1478],Rossianidae[&type=leaf,weight=2])Limnephiloidea[&type=internal,weight=1480],mrcaott28856ott80465[&type=leaf,weight=569])mrcaott15023ott28856[&type=internal,weight=2049],Phryganeidae[&type=leaf,weight=86])mrcaott15023ott38018[&type=internal,weight=2135],mrcaott200964ott222295[&type=leaf,weight=55])mrcaott15023ott200964[&type=internal,weight=2190],other_mrcaott15023ott222298[&type=other,count=2,weight=8])mrcaott15023ott222298[&type=internal,weight=2198])mrcaott869ott15023[&type=internal,weight=4918],(Rhyacophiloidea[&type=leaf,weight=1211],Glossosomatoidea[&type=leaf,weight=684])mrcaott39785ott46148[&type=internal,weight=1895])mrcaott869ott39785[&type=internal,weight=6813],(((mrcaott28840ott243303[&type=leaf,weight=1164],other_mrcaott28840ott245379[&type=other,count=5,weight=212])mrcaott28840ott245379[&type=internal,weight=1376],other_Hydroptilidae[&type=other,count=40,weight=700])Hydroptilidae[&type=internal,weight=2076])Hydroptiloidea[&type=internal,weight=2076])Integripalpia[&type=internal,weight=8889],((((mrcaott22407ott111311[&type=leaf,weight=1201],other_mrcaott22407ott49058[&type=other,count=2,weight=481])mrcaott22407ott49058[&type=internal,weight=1682],other_Psychomyioidea[&type=other,count=114,weight=263])Psychomyioidea[&type=internal,weight=1945],(Philopotamidae[&type=leaf,weight=1084],Stenopsychidae[&type=leaf,weight=103])Philopotamoidea[&type=internal,weight=1187])mrcaott22404ott122501[&type=internal,weight=3132],(((mrcaott3413ott3420[&type=leaf,weight=1410],mrcaott276929ott324536[&type=leaf,weight=155])mrcaott3413ott276929[&type=internal,weight=1565],other_Hydropsychidae[&type=other,count=16,weight=416])Hydropsychidae[&type=internal,weight=1981])Hydropsychoidea[&type=internal,weight=1981],'Annulipalpia sp. AD-2013'[&type=leaf,weight=1,supertree_leaf=1])Annulipalpia[&type=internal,weight=5114],'Trichoptera environmental sample'[&type=leaf,weight=1,supertree_leaf=1])Trichoptera[&type=internal,weight=14004])Amphiesmenoptera;",
"((((((((Cheumatopsyche[&type=leaf,weight=321],((Hydromanicus[&type=leaf,weight=58],mrcaott93832ott234617[&type=leaf,weight=16])mrcaott93832ott375067[&type=internal,weight=74],Abacaria[&type=leaf,weight=8])mrcaott93832ott145376[&type=internal,weight=82],'Hydropsyche guttata'[&type=leaf,weight=1,supertree_leaf=1])mrcaott3420ott93832[&type=internal,weight=404],(Potamyia[&type=leaf,weight=32],mrcaott54396ott54399[&type=leaf,weight=22])mrcaott54396ott375069[&type=internal,weight=54])mrcaott3420ott54396[&type=internal,weight=458],other_Hydropsychinae[&type=other,count=487,weight=522],Mexipsyche[&type=leaf,weight=32],Orthopsyche[&type=leaf,weight=21])Hydropsychinae[&type=internal,weight=1033],((((Smicridea[&type=leaf,weight=243],other_Smicrideinae[&type=other,count=3,weight=7])Smicrideinae[&type=internal,weight=250],Sciadorus[&type=leaf,weight=2])mrcaott97240ott535497[&type=internal,weight=252],Diplectrona[&type=leaf,weight=105])mrcaott3415ott97240[&type=internal,weight=357],Homoplectra[&type=leaf,weight=19],Oropsyche[&type=leaf,weight=1])mrcaott3413ott3415[&type=internal,weight=377])mrcaott3413ott3420[&type=internal,weight=1410],(Macrostemum[&type=leaf,weight=98],(Arctopsyche[&type=leaf,weight=28],Parapsyche[&type=leaf,weight=26],Maesaipsyche[&type=leaf,weight=3])Arctopsychinae[&type=internal,weight=57])mrcaott276929ott324536[&type=internal,weight=155])mrcaott3413ott276929[&type=internal,weight=1565],other_Hydropsychidae[&type=other,count=12,weight=47],(Leptonema[&type=leaf,weight=211],((Macronema[&type=leaf,weight=51],(Centromacronema[&type=leaf,weight=17],mrcaott151274ott3026123[&type=leaf,weight=15])mrcaott151274ott3026031[&type=internal,weight=32])mrcaott151274ott313718[&type=internal,weight=83],Trichomacronema[&type=leaf,weight=6])mrcaott151268ott151274[&type=internal,weight=89])mrcaott151268ott688737[&type=internal,weight=300],(Polymorphanisus[&type=leaf,weight=20],Aethaloptera[&type=leaf,weight=5])Polymorphanisini[&type=internal,weight=25],Amphipsyche[&type=leaf,weight=24],Arcyphysa[&type=leaf,weight=20])Hydropsychidae[&type=internal,weight=1981])Hydropsychoidea[&type=internal,weight=1981])Annulipalpia;"

];
*/

foreach ($newick_strings as $index => $newick)
{
	$t = parse_newick($newick);
	
	// map between labels and nodes
	$n = new NodeIterator ($t->GetRoot());
	$q = $n->Begin();
	while ($q != NULL)
	{	
		if (isset($q->label))
		{
			$t->label_to_node_map[$q->label] = $q;
		}
		$q = $n->Next();
	}
		
	treexy($t);
	
	// dump
	if (0)
	{
		echo "   id label     w    x    y\n";
		$n = new NodeIterator ($t->GetRoot());
		$q = $n->Begin();
		while ($q != NULL)
		{	
			echo str_pad($q->id, 5, ' ', STR_PAD_LEFT) . " " . str_pad($q->label, 5, ' ', STR_PAD_LEFT) . " " . str_pad($q->weight, 5, ' ', STR_PAD_LEFT) . " " . str_pad($q->x, 5, ' ', STR_PAD_LEFT) . " " . str_pad($q->y, 5, ' ', STR_PAD_LEFT) . "\n";
			$q = $n->Next();
		}
		
		foreach ($t->label_to_node_map as $label => $node)
		{
			echo $label . ' ' . $node->id . "\n";
		}
	}
	
	// JSON dump
	
	$obj = new stdclass;
	$obj->nodes = [];
	$obj->edges = [];
	
	foreach ($t->label_to_node_map as $label => $node)
	{
		$node_obj = new stdclass;
		$node_obj->id = $label;
		$node_obj->label = $label;
		$node_obj->x = $node->x;
		$node_obj->y = $node->y;
		
		$obj->nodes[] = $node_obj;	
		
		$anc = $node->GetAncestor();
		if ($anc)
		{
			$edge_obj = new stdclass;
			$edge_obj->source = $anc->GetLabel();
			$edge_obj->target = $node->GetLabel();
			
			$obj->edges[] = $edge_obj;
		}
	}
	

	print_r($obj);
	
	echo json_encode($obj) . "\n";
	
	file_put_contents('tree' . ($index + 1) . '.json', json_encode($obj));

	
	$svg = drawtree($t);	
	file_put_contents($index . '.svg', $svg);
}



?>

