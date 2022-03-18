/* Perf pour un gros export:
 * - https://stackoverflow.com/a/56513729/1346819 (d'où ce .java)
 * - https://www.visualcron.com/forum.aspx?g=Posts&t=10019 (mentionne le C comme le + rapide; aussi qu'Oracle permet de créer de pseudo-tables en "ORGANIZATION EXTERNAL" qui pondent un CSV)
 */

/*
	[ -f opencsv.jar ] || curl -L -O https://github.com/hyee/OpenCSV/raw/master/release/opencsv.jar
	javac -cp opencsv.jar Sqlmoins.java
 */

package eu.outters.sqleur;

import com.opencsv.CSVWriter;
import com.opencsv.ResultSetHelperService;

import java.sql.*;

public class Sqlmoins
{
    public static void main(String[] args) throws Exception {

    // write your code here
        //step1 load the driver class
        Class.forName("oracle.jdbc.driver.OracleDriver");

//step2 create  the connection object
        Connection con= DriverManager.getConnection(
                "jdbc:oracle:thin:@host:port:service_name",
                "ora_user","password");

//step3 create the statement object
        Statement stmt=con.createStatement();

//step4 execute query
        ResultSet rs=stmt.executeQuery("select c1,c2,c3 from my shitty table");
//        while(rs.next())
//            System.out.println(rs.getInt(1)+"  "+rs.getString(2)+"  "+rs.getString(3));

//step5 close the connection object


        String fileName = "C:\\Temp\\output.csv";
        boolean async = true;

        try (CSVWriter writer = new CSVWriter(fileName)) {

            //Define fetch size(default as 30000 rows), higher to be faster performance but takes more memory
            ResultSetHelperService.RESULT_FETCH_SIZE=50000;
            //Define MAX extract rows, -1 means unlimited.
            ResultSetHelperService.MAX_FETCH_ROWS=-1;
            writer.setAsyncMode(async);
            int result = writer.writeAll(rs, true);
            //return result - 1;
            System.out.println("Result: " + (result - 1));
        }
        con.close();
    }

    //Extract ResultSet to CSV file, auto-compress if the fileName extension is ".zip" or ".gz"
//Returns number of records extracted
    public static int ResultSet2CSV(final ResultSet rs, final String fileName, final String header, final boolean aync) throws Exception {
        try (CSVWriter writer = new CSVWriter(fileName)) {
            //Define fetch size(default as 30000 rows), higher to be faster performance but takes more memory
            ResultSetHelperService.RESULT_FETCH_SIZE=10000;
            //Define MAX extract rows, -1 means unlimited.
            ResultSetHelperService.MAX_FETCH_ROWS=20000;
            writer.setAsyncMode(aync);
            int result = writer.writeAll(rs, true);
            return result - 1;
        }
    }
}
