<?php
/**
 * HANA XML to SQL
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the LICENSE.md file.
 *
 * @author Marcel Scherello <hanaxmltosql@scherello.de>
 * @copyright 2016-2019 Marcel Scherello
 */

$xml = new SimpleXMLElement($xml_input);

$select = array();
$nodes = $xml->viewNode;
foreach ($nodes as $node) {
    $name = "x_" . $node->attributes()["name"] . "";
    $type = "";
    $select_fields = "";
    $select_from = "";
    $select_where = "";
    $select_options = "";

    if ($node->attributes("xsi", TRUE)->type == "View:Projection") $type = "projection";
    elseif ($node->attributes("xsi", TRUE)->type == "View:JoinNode") $type = "join";
    elseif ($node->attributes("xsi", TRUE)->type == "View:Aggregation") $type = "aggregation";
    elseif ($node->attributes("xsi", TRUE)->type == "View:Rank") $type = "rank";

    if ($type == "join") $quelle = "x_" . substr($node->input[0]->viewNode, 3) . ".";
    else $quelle = "";


    # ************ selection fields from main/first table ************ 
    $felder = $node->input[0]->children();
    $i = 0;
    foreach ($felder as $a) {
        if ($a->attributes()["sourceName"]) {
            if ($i != 0) $select_fields .= " , ";
            if ($node->element[$i]->attributes()["aggregationBehavior"]) {
                $aggregierung = $node->element[$i]->attributes()["aggregationBehavior"];
                if ($aggregierung == "SUM") $select_fields .= "SUM(";
            }
            $select_fields .= $quelle . $a->attributes()["sourceName"];
            if ($aggregierung == "SUM") $select_fields .= ")";
            $select_fields .= " as ";
            $select_fields .= $a->attributes()["targetName"];
            $aggregierung = "";
            $i++;
        }
    }

    # ************ selection fields from second table ************  
    if ($type == "join") {
        $felder = $node->input[1]->children();
        $quelle = "x_" . substr($node->input[1]->viewNode, 3) . ".";
        $i = 0;
        foreach ($felder as $a) {
            if ($a->attributes()["sourceName"]) {
                $select_fields .= " , ";
                $select_fields .= $quelle . $a->attributes()["sourceName"];
                $select_fields .= " as ";
                $select_fields .= $a->attributes()["targetName"];
                $i++;
            }
        }
    }

    # ************ extra fields from formulars ************  
    if ($type == "aggregation" OR $type == "projection" OR $type == "join") {
        $felder = $node->element;
        foreach ($felder as $a) {
            if ($a->calculationDefinition) {
                $select_fields .= " , ";
                $formular = $a->calculationDefinition->formula;
                if ($a->inlineType->attributes()["name"] == "INTEGER") {
                    #$select_fields .= "'".$a->calculationDefinition->formula."'";
                    $select_fields .= $a->calculationDefinition->formula;
                } elseif (substr($formular, 0, 2) == "if") {
                    $if_string = $formular;
                    $if_string = str_replace('if(', 'CASE WHEN ', $if_string);
                    $if_string = preg_replace('/,/', ' THEN ', $if_string, 1);
                    $if_string = str_replace(',', ' ELSE', $if_string);
                    $if_string = substr($if_string, 0, -1) . " END";

                    $ifs = preg_match_all('*isNull*', $if_string, $matches);

                    for ($if_count = 1; $if_count <= $ifs; $if_count++) {
                        preg_match('/isNull.*?\\)/', $if_string, $matches);
                        $old_string = $matches[0];
                        $fieldname = explode('"', $matches[0]);
                        $fieldname = $fieldname[1];
                        $new_string = $fieldname . " IS NULL";
                        $if_string = str_replace($old_string, $new_string, $if_string);
                        #$select_fields .= "test:".$fieldname;
                    }

                    $if_string = str_replace('"', "", $if_string);
                    $select_fields .= $if_string;
                } else {
                    $formular = str_replace("rightstr", "RIGHT", $formular);
                    $formular = str_replace("leftstr", "LEFT", $formular);
                    $formular = str_replace('"', "", $formular);
                    $select_fields .= $formular;
                }
                $select_fields .= " as ";
                $select_fields .= $a->attributes()["name"];
                $i++;
            }
        }
    } elseif ($type == "rank") {
        $partitionElement = explode("/", $node->windowFunction->partitionElement);
        $order = explode("/", $node->windowFunction->children()[1]->attributes()[0]);
        $rankElement = explode("/", $node->windowFunction->rankElement);
        $select_fields .= " , RANK() OVER (";
        $select_fields .= "PARTITION BY ";
        $select_fields .= $partitionElement[3];
        $select_fields .= " ORDER BY ";
        $select_fields .= $order[3];
        $select_fields .= ") as ";
        $select_fields .= $rankElement[3];
    }

    # ************ from ************ 

    if ($type == "projection" OR $type == "aggregation" OR $type == "rank") {
        if (substr($node->input->entity, 3)) {
            $teile = explode(".", substr($node->input->entity, 3));
            if ($teile[0] == '"ABAP"') $select_from = str_replace('"ABAP"', '"SAPABAP1"', substr($node->input->entity, 3)) . '"';
            else $select_from = '"' . substr($node->input->entity, 3) . '"';
            $select_from = str_replace('".', '"."', $select_from);
            $select_from = str_replace('""', '"', $select_from);
        } else $select_from = '(' . PHP_EOL . $select["x_" . substr($node->input->viewNode, 3)] . ' )';
    } elseif ($type == "join") {
        $first_partner = "x_" . substr($node->input[0]->viewNode, 3);
        $second_partner = "x_" . substr($node->input[1]->viewNode, 3);
        $join_type = $node->join->attributes()["joinType"];

        if ($join_type == "leftOuter") $join_type = "LEFT OUTER JOIN";
        elseif ($join_type == "rightOuter") $join_type = "RIGHT OUTER JOIN";
        elseif ($join_type == "inner") $join_type = "INNER JOIN";
        else $join_type = "JOIN";

        $select_from = "((" . PHP_EOL . $select[$first_partner] . PHP_EOL . ")";
        $select_from .= " " . $first_partner . PHP_EOL;
        $select_from .= $join_type . PHP_EOL;
        $select_from .= "(" . PHP_EOL . $select[$second_partner] . PHP_EOL . ")";
        $select_from .= " " . $second_partner . PHP_EOL;
        $select_from .= "ON " . PHP_EOL;

        $felder = $node->join->children();
        $join_cond = "";
        $middle = count($felder) / 2;
        for ($i = 0; $i < count($felder) / 2; $i = $i) {
            if ($i != 0) $select_from .= " AND ";
            $select_from .= $first_partner . "." . $felder[$i] . " = " . $second_partner . "." . $felder[$i + $middle];
            $i++;
        }

        $select_from .= " )";
    }


    # ************ WHERE ************ 

    $i = 0;
    $felder = $node->elementFilter;
    foreach ($felder as $a) {
        if ($i == 0) $select_where = PHP_EOL . "WHERE ";
        else $select_where .= " AND ";

        $filterfeld = $a->attributes()["elementName"];
        $operator = $a->valueFilter->attributes()["operator"];
        $including = $a->valueFilter->attributes()["including"];

        if ($operator == "IN") {
            $select_where .= $filterfeld . " IN (";
            $v2 = 0;
            foreach ($a->valueFilter->operands as $v) {
                if ($v2 != 0) $select_where .= " , ";
                $wert = $v->attributes()["value"];
                $select_where .= "'" . $wert . "'";
                $v2++;
            }
            $select_where .= ")";
        } elseif ($operator == "CP") {
            $select_where .= "contains(" . $filterfeld . ", '" . $a->valueFilter->attributes()["value"] . "')";
        } elseif ($operator == "BT") {
            $select_where .= $filterfeld . " BETWEEN '" . $a->valueFilter->attributes()["lowValue"] . "' AND '" . $a->valueFilter->attributes()["highValue"] . "'";
        } elseif ($including == "true") {
            $select_where .= $filterfeld . " = '" . $a->valueFilter->attributes()["value"] . "'";
        } elseif ($including == "false") {
            $select_where .= $filterfeld . " <> '" . $a->valueFilter->attributes()["value"] . "'";
        }
        $i++;
    }
    $felder = $node->filterExpression;
    foreach ($felder as $a) {
        if ($i == 0) $select_where = PHP_EOL . "WHERE ";
        else $select_where .= " AND ";

        $filterfeld = $a->formula;
        if (substr($filterfeld, 0, 5) == "(in (") {
            #$select_where .= substr($filterfeld, 0, 5);
            $result = explode('"', $filterfeld);
            $in_string = $result[2];
            $in_string = preg_replace('/,/', '', $in_string, 1);
            $in_string = substr($in_string, 0, -1);
            $select_where .= $result[1] . " IN (" . $in_string;
        } else $select_where .= $filterfeld;
        $i++;
    }

    # ************ extra options - Order by ************  
    if ($type == "aggregation") {
        $i = 0;
        $felder = $node->element;
        foreach ($felder as $a) {
            if (!$a->attributes()["aggregationBehavior"]) {
                if ($i == 0) $select_options = PHP_EOL . "GROUP BY ";
                else $select_options .= " , ";
                $select_options .= $a->attributes()["name"];
                $i++;
            }
        }
    }

    $select_text = "SELECT";
    $select_text .= PHP_EOL . $select_fields;
    $select_text .= PHP_EOL . 'FROM';
    $select_text .= PHP_EOL . $select_from;
    $select_text .= $select_where;
    $select_text .= $select_options;

    $select[$name] = $select_text;
    $last_name = $name;
}
#print_r($select); 
#echo $select[$xml_output];
#echo $select["Join_1"];
if ($xml_output == "") {
    $xml_output = $last_name;
}
$result = $select[$xml_output];

?>
