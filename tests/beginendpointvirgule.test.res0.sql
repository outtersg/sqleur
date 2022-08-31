begin
	youp;
end;--#

select bla from truc order by case when zoup then 1 end--#

begin--#
plop--#
commit--#

begin plop; end ;--#

begin transaction--#
begin rien ; end ;--#
commit--#

begin youpla; begin youpi; end ; coucou; end  ;--#

begin youpla; begin youpi; end;end  ;--#

begin begin youpi; end;end ;--#

for machin in truc loop coucou--#
end loop--#

-- Mic-mac entre déclarations de fonctions à end et sans end:
create function bla() return integer as begin select 1 from dual order by case when 1 = 1 then 1 end; end--#
create function bla() return integer as maVar integer; begin select 1 from dual order by case when 1 = 1 then 1 end; end--#
create function bla() return integer
as
	maVar integer;
begin
	select 1 from dual order by case when 1 = 1 then 1 end;
end--#
create function bla() return integer as $$ select 1; $$--#
create function bla() return integer as
$$
	select 1;
$$--#
create table bla as select 1--#
create package machin as function bla() as maVar integer; begin coucou; end; end machin--#
