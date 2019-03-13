-- prepro SqleurPreproPif
instruction toujours en premier;
#pif [
#pif /instruction [A-Z]/ après /instruction [0-9]/
instruction 0;
instruction 1;
#pif A
instruction A;
#pif B
instruction B;
#pif A1 après A
instruction A1 après A;
#pif B1 après B
instruction B1 après B;
instruction 9;
#pif ]
instruction toujours en dernier;
