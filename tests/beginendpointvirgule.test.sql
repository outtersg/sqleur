-- Ce test est un peu particulier car il teste l'embarquement du ; final dans le SQL sorti;
-- or ce ; est fusionné avec celui de séparation de requêtes: pour distinguer les deux il faut passer en mode -0.
-- Donc test en pur shell:
#if 0
esp="`printf '\100'`"
cat tests/beginendpointvirgule.test.res0.sql > /tmp/1
php sql2csv.php -E -0 tests/beginendpointvirgule.test.sql | tr '\000' $esp | sed -e "s/$esp/--#$esp/g" | tr $esp '\012' > /tmp/2
for f in 1 2
do
	sed -e 's/ *--[^#].*//' -e '/^$/d' < /tmp/$f > /tmp/$f.propre
done
diff -uw /tmp/2.propre /tmp/1.propre
#endif

-- À la SQL*Plus:
begin
	youp;
end;
/

select bla from truc order by case when zoup then 1 end;

begin; plop; commit   ;

begin plop; end ;

begin transaction; begin rien ; end ; commit;

begin youpla; begin youpi; end ; coucou; end  ;

begin youpla; begin youpi; end;end  ;

begin begin youpi; end;end ;

for machin in truc loop coucou; end loop;
