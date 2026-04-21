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
"((((((((((((((((mrcaott31017ott134468[&type=leaf,weight=9],other_mrcaott31017ott341459[&type=other,count=2,weight=2])mrcaott31017ott341459[&type=internal,weight=11],mrcaott31011ott31021[&type=leaf,weight=8])mrcaott31011ott31017[&type=internal,weight=19],mrcaott457331ott471659[&type=leaf,weight=2])mrcaott31011ott457331[&type=internal,weight=21],'Puffinus subalaris'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31011ott501951[&type=internal,weight=22],other_Puffinus[&type=other,count=26,weight=26])Puffinus[&type=internal,weight=48],Ardenna[&type=leaf,weight=7])mrcaott31011ott471652[&type=internal,weight=55],Calonectris[&type=leaf,weight=5])mrcaott31011ott82215[&type=internal,weight=60],Procellaria[&type=leaf,weight=13])mrcaott31011ott379429[&type=internal,weight=73],mrcaott172670ott944663[&type=leaf,weight=8])mrcaott31011ott172670[&type=internal,weight=81],(((((mrcaott73703ott271044[&type=leaf,weight=10],'Pterodroma inexpectata'[&type=leaf,weight=1,supertree_leaf=1])mrcaott73703ott85282[&type=internal,weight=11],'Pterodroma axillaris'[&type=leaf,weight=1,supertree_leaf=1])mrcaott73703ott845409[&type=internal,weight=12],mrcaott319464ott485716[&type=leaf,weight=2])mrcaott73703ott319464[&type=internal,weight=14],mrcaott271031ott845415[&type=leaf,weight=5])mrcaott73703ott271031[&type=internal,weight=19],other_Pterodroma[&type=other,count=10,weight=10],((mrcaott271029ott271036[&type=leaf,weight=10],'Pterodroma mollis'[&type=leaf,weight=2])mrcaott271029ott271043[&type=internal,weight=12],'Pterodroma hypoleuca'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271029ott797520[&type=internal,weight=13])Pterodroma[&type=internal,weight=42])mrcaott31011ott73703[&type=internal,weight=123],(((Pachyptila[&type=leaf,weight=9],Halobaena[&type=leaf,weight=3])mrcaott85289ott656582[&type=internal,weight=12],Pelecanoididae[&type=leaf,weight=7])mrcaott85289ott603893[&type=internal,weight=19],Aphrodroma[&type=leaf,weight=1])mrcaott85271ott85289[&type=internal,weight=20])mrcaott31011ott85271[&type=internal,weight=143],((mrcaott82196ott603906[&type=leaf,weight=8],Daption[&type=leaf,weight=2])mrcaott82196ott726152[&type=internal,weight=10],mrcaott656580ott944660[&type=leaf,weight=2])mrcaott82196ott656580[&type=internal,weight=12])mrcaott31011ott82196[&type=internal,weight=155],((mrcaott65050ott603904[&type=leaf,weight=12],(mrcaott406716ott3595642[&type=leaf,weight=8],'Hydrobates homochroa'[&type=leaf,weight=1,supertree_leaf=1])mrcaott406716ott1021954[&type=internal,weight=9])Hydrobates[&type=internal,weight=21],Oceanodroma[&type=leaf,weight=2])mrcaott65050ott3595638[&type=internal,weight=23])mrcaott31011ott65050[&type=internal,weight=178],((mrcaott18209ott49107[&type=leaf,weight=11],mrcaott134492ott1019090[&type=leaf,weight=6])mrcaott18209ott134492[&type=internal,weight=17],other_mrcaott18206ott18209[&type=other,count=3,weight=4])mrcaott18206ott18209[&type=internal,weight=21],Thalassidroma[&type=leaf,weight=1])mrcaott18206ott31011[&type=internal,weight=200],((Diomedea[&type=leaf,weight=11],Phoebastria[&type=leaf,weight=5])mrcaott71459ott320282[&type=internal,weight=16],((mrcaott134477ott320280[&type=leaf,weight=9],mrcaott134478ott320276[&type=leaf,weight=2])Thalassarche[&type=internal,weight=11],Phoebetria[&type=leaf,weight=2])mrcaott134477ott592673[&type=internal,weight=13],Plotornis[&type=leaf,weight=2])Diomedeidae[&type=internal,weight=31])Procellariiformes[&type=internal,weight=231])mrcaott18206ott60413;",
//"(((((((((((mrcaott319472ott449678[&type=leaf,weight=2],'Pterodroma phaeopygia'[&type=leaf,weight=1,supertree_leaf=1])mrcaott319472ott485717[&type=internal,weight=3],'Pterodroma externa'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271035ott319472[&type=internal,weight=4],'Pterodroma cervicalis'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271035ott461783[&type=internal,weight=5],'Pterodroma ultima'[&type=leaf,weight=1,supertree_leaf=1])mrcaott73703ott271035[&type=internal,weight=6],((mrcaott285635ott461784[&type=leaf,weight=2],'Pterodroma arminjoniana'[&type=leaf,weight=1,supertree_leaf=1])mrcaott285635ott666330[&type=internal,weight=3],'Pterodroma neglecta'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271044ott285635[&type=internal,weight=4])mrcaott73703ott271044[&type=internal,weight=10],'Pterodroma inexpectata'[&type=leaf,weight=1,supertree_leaf=1])mrcaott73703ott85282[&type=internal,weight=11],'Pterodroma axillaris'[&type=leaf,weight=1,supertree_leaf=1])mrcaott73703ott845409[&type=internal,weight=12],mrcaott319464ott485716[&type=leaf,weight=2])mrcaott73703ott319464[&type=internal,weight=14],(((mrcaott3595611ott4947457[&type=leaf,weight=2],'Pterodroma pycrofti'[&type=leaf,weight=1,supertree_leaf=1])mrcaott3595611ott3595620[&type=internal,weight=3],'Pterodroma cookii'[&type=leaf,weight=1,supertree_leaf=1])mrcaott845415ott3595611[&type=internal,weight=4],'Pterodroma longirostris'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271031ott845415[&type=internal,weight=5])mrcaott73703ott271031[&type=internal,weight=19],other_Pterodroma[&type=other,count=6,weight=6],((((((mrcaott713614ott950182[&type=leaf,weight=2],'Pterodroma cahow'[&type=leaf,weight=1,supertree_leaf=1])mrcaott713614ott845408[&type=internal,weight=3],'Pterodroma hasitata'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271036ott713614[&type=internal,weight=4],'Pterodroma imberi'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271036ott5925783[&type=internal,weight=5],((('Pterodroma macroptera'[&type=leaf,weight=2],'Pterodroma lessonii'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271034ott581903[&type=internal,weight=3],'Pterodroma incerta'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271033ott271034[&type=internal,weight=4],'Pterodroma magentae'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271029ott271033[&type=internal,weight=5])mrcaott271029ott271036[&type=internal,weight=10],'Pterodroma mollis'[&type=leaf,weight=2])mrcaott271029ott271043[&type=internal,weight=12],'Pterodroma hypoleuca'[&type=leaf,weight=1,supertree_leaf=1])mrcaott271029ott797520[&type=internal,weight=13],'Pterodroma wortheni'[&type=leaf,weight=1,supertree_leaf=1],'Pterodroma oliveri'[&type=leaf,weight=1,supertree_leaf=1],'Pterodroma deceptornis'[&type=leaf,weight=1,supertree_leaf=1],'Pterodroma chionophara'[&type=leaf,weight=1,supertree_leaf=1])Pterodroma[&type=internal,weight=42])mrcaott31011ott73703;",
//"((((((((((mrcaott828823ott5860910[&type=leaf,weight=2],'Puffinus myrtae'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31017ott828823[&type=internal,weight=3],'Puffinus bannermani'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31017ott4947459[&type=internal,weight=4],('Puffinus bailloni'[&type=leaf,weight=2],'Puffinus persicus'[&type=leaf,weight=1,supertree_leaf=1])mrcaott828827ott7068757[&type=internal,weight=3])mrcaott31017ott828827[&type=internal,weight=7],mrcaott134468ott351968[&type=leaf,weight=2])mrcaott31017ott134468[&type=internal,weight=9],other_mrcaott31017ott341459[&type=other,count=2,weight=2])mrcaott31017ott341459[&type=internal,weight=11],((mrcaott31021ott471650[&type=leaf,weight=3],mrcaott471651ott471658[&type=leaf,weight=2])mrcaott31021ott471651[&type=internal,weight=5],(mrcaott31013ott946840[&type=leaf,weight=2],'Puffinus loyemilleri'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31011ott31013[&type=internal,weight=3])mrcaott31011ott31021[&type=internal,weight=8])mrcaott31011ott31017[&type=internal,weight=19],mrcaott457331ott471659[&type=leaf,weight=2])mrcaott31011ott457331[&type=internal,weight=21],'Puffinus subalaris'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31011ott501951[&type=internal,weight=22],other_Puffinus[&type=other,count=10,weight=10],'Puffinus parvus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus mariae'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus cuneatus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus chlororhynchus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus micraulax'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus inceptor'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus diatomicus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus calhouni'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus barnesi'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus heinrothi'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus mcgalli'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus priscus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus gilmorei'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus raemdonckii'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus reinholdi'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus mitchelli'[&type=leaf,weight=1,supertree_leaf=1])Puffinus[&type=internal,weight=48])mrcaott31011ott471652;"
//"((((((((((mrcaott828823ott5860910[&type=leaf,weight=2],'Puffinus myrtae'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31017ott828823[&type=internal,weight=3],'Puffinus bannermani'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31017ott4947459[&type=internal,weight=4],('Puffinus bailloni'[&type=leaf,weight=2],'Puffinus persicus'[&type=leaf,weight=1,supertree_leaf=1])mrcaott828827ott7068757[&type=internal,weight=3])mrcaott31017ott828827[&type=internal,weight=7],mrcaott134468ott351968[&type=leaf,weight=2])mrcaott31017ott134468[&type=internal,weight=9],other_mrcaott31017ott341459[&type=other,count=2,weight=2])mrcaott31017ott341459[&type=internal,weight=11],((mrcaott31021ott471650[&type=leaf,weight=3],mrcaott471651ott471658[&type=leaf,weight=2])mrcaott31021ott471651[&type=internal,weight=5],(mrcaott31013ott946840[&type=leaf,weight=2],'Puffinus loyemilleri'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31011ott31013[&type=internal,weight=3])mrcaott31011ott31021[&type=internal,weight=8])mrcaott31011ott31017[&type=internal,weight=19],('Puffinus gavia'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus huttoni'[&type=leaf,weight=1,supertree_leaf=1])mrcaott457331ott471659[&type=internal,weight=2])mrcaott31011ott457331[&type=internal,weight=21],'Puffinus subalaris'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31011ott501951[&type=internal,weight=22],'Puffinus parvus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus mariae'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus cuneatus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus chlororhynchus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus micraulax'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus inceptor'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus diatomicus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus calhouni'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus barnesi'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus heinrothi'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus mcgalli'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus priscus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus gilmorei'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus raemdonckii'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus reinholdi'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus mitchelli'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus dichrous'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus nicolae'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus polynesiae'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus haurakiensis'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus colstoni'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus atrodorsalis'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus nativitatis'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus tunneyi'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus temptator'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus kermadecensis'[&type=leaf,weight=1,supertree_leaf=1])Puffinus[&type=internal,weight=48])mrcaott31011ott471652;",
"(((((((((((mrcaott828823ott5860910[&type=leaf,weight=2],'Puffinus myrtae'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31017ott828823[&type=internal,weight=3],'Puffinus bannermani'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31017ott4947459[&type=internal,weight=4],('Puffinus bailloni'[&type=leaf,weight=2],'Puffinus persicus'[&type=leaf,weight=1,supertree_leaf=1])mrcaott828827ott7068757[&type=internal,weight=3])mrcaott31017ott828827[&type=internal,weight=7],mrcaott134468ott351968[&type=leaf,weight=2])mrcaott31017ott134468[&type=internal,weight=9],other_mrcaott31017ott341459[&type=other,count=2,weight=2])mrcaott31017ott341459[&type=internal,weight=11],((mrcaott31021ott471650[&type=leaf,weight=3],mrcaott471651ott471658[&type=leaf,weight=2])mrcaott31021ott471651[&type=internal,weight=5],(mrcaott31013ott946840[&type=leaf,weight=2],'Puffinus loyemilleri'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31011ott31013[&type=internal,weight=3])mrcaott31011ott31021[&type=internal,weight=8])mrcaott31011ott31017[&type=internal,weight=19],mrcaott457331ott471659[&type=leaf,weight=2])mrcaott31011ott457331[&type=internal,weight=21],'Puffinus subalaris'[&type=leaf,weight=1,supertree_leaf=1])mrcaott31011ott501951[&type=internal,weight=22],other_Puffinus[&type=other,count=5,weight=5],'Puffinus parvus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus mariae'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus cuneatus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus chlororhynchus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus micraulax'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus inceptor'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus diatomicus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus calhouni'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus barnesi'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus heinrothi'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus mcgalli'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus priscus'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus gilmorei'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus raemdonckii'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus reinholdi'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus mitchelli'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus dichrous'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus nicolae'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus polynesiae'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus haurakiensis'[&type=leaf,weight=1,supertree_leaf=1],'Puffinus colstoni'[&type=leaf,weight=1,supertree_leaf=1])Puffinus[&type=internal,weight=48],((((mrcaott946833ott946834[&type=leaf,weight=2],'Ardenna gravis'[&type=leaf,weight=1,supertree_leaf=1])mrcaott726165ott946833[&type=internal,weight=3],'Ardenna grisea'[&type=leaf,weight=1,supertree_leaf=1])mrcaott726165ott726166[&type=internal,weight=4],'Ardenna tenuirostris'[&type=leaf,weight=1,supertree_leaf=1])mrcaott471652ott726165[&type=internal,weight=5],mrcaott471653ott946835[&type=leaf,weight=2])Ardenna[&type=internal,weight=7])mrcaott31011ott471652[&type=internal,weight=55])mrcaott31011ott82215;",
];

/*
$newick_strings = [
'((a,b)x,c)z;',
'(((a,b)x,c)z)w;',
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

